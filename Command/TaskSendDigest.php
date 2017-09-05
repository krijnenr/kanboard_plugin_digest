<?php

namespace Kanboard\Plugin\Digest\Command;

use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskModel;
use Kanboard\Model\UserModel;


use Pimple\Container;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Kanboard\Event\TaskListEvent;

class TaskSendDigest extends Command
{
	/**
	 * Container instance
	 *
	 * @access protected
	 * @var \Pimple\Container
	 */
	protected $container;
	
	/**
	 * Constructor
	 *
	 * @access public
	 * @param  \Pimple\Container   $container
	 */
	public function __construct(Container $container)
	{
		parent::__construct();
		$this->container = $container;
	}
	
	/**
	 * Load automatically models
	 *
	 * @access public
	 * @param  string $name Model name
	 * @return mixed
	 */
	public function __get($name)
	{
		return $this->container[$name];
	}
	
    protected function configure()
    {
        $this
            ->setName('digest')
            ->setDescription('Send notifications for daily digest')
            ->addOption('show', null, InputOption::VALUE_NONE, 'Show sent digest')
            ->addOption('daily', null, InputOption::VALUE_NONE, 'Send daily digest (default) for all modified tasks')
            ->addOption('weekly', null, InputOption::VALUE_NONE, 'Send weekly digest (default) for all modified tasks')
        ;
    }
    
    protected function execute(InputInterface $input, OutputInterface $output)
    {
		$projects = $this->projectModel->getAllByStatus(ProjectModel::ACTIVE);
        foreach ($projects as $project) {
        		$SendDailyDigest = $this->projectMetadataModel->exists($project['id'], 'send_digest');
        		if ($SendDailyDigest) {
        			$send_daily = $this->projectMetadataModel->get($project['id'], 'send_digest');
        			if (strcasecmp($send_daily, "yes") != 0) {
        				$SendDailyDigest = 0;	
        			}
        		}

        		$SendWeeklyDigest = $this->projectMetadataModel->exists($project['id'], 'send_weekly_digest');
        		if ($SendWeeklyDigest) {
        			$send_weekly_digest = $this->projectMetadataModel->get($project['id'], 'send_weekly_digest');
        			if (strcasecmp($send_weekly_digest, "yes") != 0) {
        				$SendWeeklyDigest = 0;	
        			}
        		}
        		        		
        		$project_id = $project['id'];

        		// debug
        		$project_name = $project['name'];
        		$wkly = $input->getOption('weekly');
            	print "Project: $project_name, Daily: $SendDailyDigest, Weekly: $SendWeeklyDigest, weekly option: $wkly\n";
            	$users = $this->projectUserRoleModel->getUsers($project_id);
            	foreach ($users as $usr) {
            		$user_name = $this->helper->user->getFullname($usr);
            		print " User: $user_name\n";
            	}
            	// end of debug

        		if ($SendWeeklyDigest and ($input->getOption('weekly'))) {
        			$lastWeek = time() - (7 * 24 * 60 * 60);
        		    $tasks = $this->getModifiedTasksQuery($project_id, $lastWeek)->findAll();
        		
        		    if ($input->getOption('show')) {
	            		$tasks = $this->sendGroupTaskNotifications($tasks, $project_id);
            			print "Weekly digest\n";
	            		$this->showTable($output, $tasks);
	            	}
        		}
        		else {
	        		if ($SendDailyDigest) {
	        		    // defalut to daily
	        			$yesterday = time() - (1 * 24 * 60 * 60);
	        			$tasks = $this->getModifiedTasksQuery($project_id, $yesterday)->findAll();
	
	        		    if ($input->getOption('show')) {
		            		//$tasks = $this->sendGroupTaskNotifications($tasks, $project_id);
		            		$tasks = $this->sendTaskNotifications($tasks, $project_id);
		            		print "Daily digest\n";
		            		$this->showTable($output, $tasks);
		            	}
	        		}
        		}
        }
    }
    
    public function getModifiedTasksQuery($project_id, $duration)
    {
    	return $this->db->table(TaskModel::TABLE)
    	->columns(
    			TaskModel::TABLE.'.id',
    			TaskModel::TABLE.'.title',
    			TaskModel::TABLE.'.date_modification',
    			TaskModel::TABLE.'.project_id',
    			TaskModel::TABLE.'.creator_id',
    			TaskModel::TABLE.'.owner_id',
    			ProjectModel::TABLE.'.name AS project_name',
    			UserModel::TABLE.'.username AS assignee_username',
    			UserModel::TABLE.'.name AS assignee_name'
    	)
    	->join(ProjectModel::TABLE, 'id', 'project_id')
    	->join(UserModel::TABLE, 'id', 'owner_id')
    	->eq(ProjectModel::TABLE.'.is_active', 1)
    	->eq(TaskModel::TABLE.'.is_active', 1)
    	->eq(TaskModel::TABLE.'.project_id', $project_id)
    	->gte(TaskModel::TABLE.'.date_modification', $duration);
    }
   
    
    public function showTable(OutputInterface $output, array $tasks)
    {
        $rows = array();

        foreach ($tasks as $task) {
            $rows[] = array(
                $task['id'],
                $task['title'],
                date('Y-m-d H:i', $task['date_modification']),
                $task['project_id'],
                $task['project_name'],
                $task['assignee_name'] ?: $task['assignee_username'],
            );
        }

        $table = new Table($output);
        $table
            ->setHeaders(array('Id', 'Title', 'Modification date', 'Project Id', 'Project name', 'Assignee'))
            ->setRows($rows)
            ->render();
    }

    /**
     * Send all modified project tasks for one user in one email
     *
     * @access public
     * @param  array $tasks
     * @return array
     */
    public function sendGroupTaskNotifications(array $tasks, $project_id)
    {
        foreach ($this->groupByColumn($tasks, 'owner_id') as $user_tasks) {
            $users = $this->userNotificationModel->getUsersWithNotificationEnabled($user_tasks[0]['project_id']);

            foreach ($users as $user) {
                $this->sendUserTaskNotifications($user, $tasks);
            }
        }

        return $tasks;
    }
    
    /**
     * Send all tasks in one email to project manager(s)
     *
     * @access public
     * @param  array $tasks
     * @return array
     */
    public function sendTaskNotificationsToManagers(array $tasks)
    {
        foreach ($this->groupByColumn($tasks, 'project_id') as $project_id => $project_tasks) {
            $users = $this->userNotificationModel->getUsersWithNotificationEnabled($project_id);
            $managers = array();

            foreach ($users as $user) {
                $role = $this->projectUserRoleModel->getUserRole($project_id, $user['id']);
                if ($role == Role::PROJECT_MANAGER) {
                    $managers[] = $user;
                }
            }

            foreach ($managers as $manager) {
                $this->sendUserTaskNotificationsToManagers($manager, $project_tasks);
            }
        }

        return $tasks;
    }
    
    /**
     * Send tasks from project
     *
     * @access public
     * @param  array $tasks
     * @return array
     */
    public function sendTaskNotifications(array $tasks, $project_id)
    {
   		$users = $this->userNotificationModel->getUsersWithNotificationEnabled($project_id);
    	
   		foreach ($users as $user) {
   			$this->sendUserTaskNotifications($user, $tasks);
   		}
    	
    	return $tasks;
    	 /*
        foreach ($this->groupByColumn($tasks, 'project_id') as $project_id => $project_tasks) {
            $users = $this->userNotificationModel->getUsersWithNotificationEnabled($project_id);

            foreach ($users as $user) {
                $this->sendUserTaskNotifications($user, $project_tasks);
            }
        }

        return $tasks;
        */
    }
    
    /**
     * Send tasks for a given user
     *
     * @access public
     * @param  array   $user
     * @param  array   $tasks
     */
    public function sendUserTaskNotifications(array $user, array $tasks)
    {
        $project_names = array();
    	if (! empty($tasks)) {
            $project_names[$task['project_id']] = $task['project_name'];
    		$this->userNotificationModel->sendUserNotification(
                $user,
                TaskModel::EVENT_OVERDUE, //EVENT_UPDATE,
                array('tasks' => $user_tasks, 'project_name' => implode(', ', $project_names))
            );
        }
    	/*
        $user_tasks = array();
        $project_names = array();

        foreach ($tasks as $task) {
            if ($this->userNotificationFilterModel->shouldReceiveNotification($user, array('task' => $task))) {
                $user_tasks[] = $task;
                $project_names[$task['project_id']] = $task['project_name'];
            }
        }

        if (! empty($user_tasks)) {
            $this->userNotificationModel->sendUserNotification(
                $user,
                TaskModel::EVENT_OVERDUE, //EVENT_UPDATE,
                array('tasks' => $user_tasks, 'project_name' => implode(', ', $project_names))
            );
        }
        */
    }

    /**
     * Send tasks for a project manager(s)
     *
     * @access public
     * @param  array   $manager
     * @param  array   $tasks
     */
    public function sendUserTaskNotificationsToManagers(array $manager, array $tasks)
    {
        $this->userNotificationModel->sendUserNotification(
            $manager,
            TaskModel::EVENT_UPDATE,
            array('tasks' => $tasks, 'project_name' => $tasks[0]['project_name'])
        );
    }

    /**
     * Group a collection of records by a column
     *
     * @access public
     * @param  array   $collection
     * @param  string  $column
     * @return array
     */
    public function groupByColumn(array $collection, $column)
    {
        $result = array();

        foreach ($collection as $item) {
            $result[$item[$column]][] = $item;
        }

        return $result;
    }
}

<?php
namespace Kanboard\Plugin\Zulip\Notification;

use Kanboard\Core\Base;
use Kanboard\Core\Notification\NotificationInterface;
use Kanboard\Model\TaskModel;

/**
 * Zulip Notification
 *
 * @package  notification
 * @author   Peter Fejer
 * @modified sarangtc
 */
class Zulip extends Base implements NotificationInterface
{
    /**
     * Send notification to a user
     *
     * @access public
     * @param  array     $user
     * @param  string    $eventName
     * @param  array     $eventData
     */
    public function notifyUser(array $user, $eventName, array $eventData)
    {
        $webhook = $this->userMetadataModel->get($user['id'], 'zulip_webhook_url', $this->configModel->get('zulip_webhook_url'));
        $api_key = $this->userMetadataModel->get($user['id'], 'zulip_webhook_botapi');
        $type = $this->userMetadataModel->get($user['id'], 'zulip_message_type');
        $channel = $this->userMetadataModel->get($user['id'], 'zulip_webhook_channel');
        $subject = $this->userMetadataModel->get($user['id'], 'zulip_webhook_subject');
        $email = $this->userMetadataModel->get($user['id'], 'zulip_webhook_email');
        if (! empty($webhook)) {
            if ($eventName === TaskModel::EVENT_OVERDUE) {
                foreach ($eventData['tasks'] as $task) {
                    $project = $this->projectModel->getById($task['project_id']);
                    $eventData['task'] = $task;
                    $this->sendMessage($webhook, $channel, $project, $eventName, $eventData, $api_key, $subject, $type, $email);
                }
            } else {
                $project = $this->projectModel->getById($eventData['task']['project_id']);
                $this->sendMessage($webhook, $channel, $project, $eventName, $eventData, $api_key, $subject, $type, $email);
            }
        }
    }

    /**
     * Send notification to a project
     *
     * @access public
     * @param  array     $project
     * @param  string    $event_name
     * @param  array     $event_data
     */
    public function notifyProject(array $project, $event_name, array $event_data)
    {
        $webhook = $this->projectMetadataModel->get($project['id'], 'zulip_webhook_url', $this->configModel->get('zulip_webhook_url'));
        $api_key = $this->projectMetadataModel->get($project['id'], 'zulip_webhook_botapi');
        // Changed from zulip_webhook_type to zulip_message_type for consistency
        $type = $this->projectMetadataModel->get($project['id'], 'zulip_message_type');
        $channel = $this->projectMetadataModel->get($project['id'], 'zulip_webhook_channel');
        $subject = $this->projectMetadataModel->get($project['id'], 'zulip_webhook_subject');
        $email = $this->projectMetadataModel->get($project['id'], 'zulip_webhook_email');
        $filters = $this->projectMetadataModel->get($project['id'], 'zulip_webhook_eventfilter');

        if (! empty($webhook)) {
          if (! empty($filters)){
            $filter_array = explode(",", strtolower(trim($filters)));
            if (in_array(strtolower($event_name), $filter_array)) {
              $this->sendMessage($webhook, $channel, $project, $event_name, $event_data, $api_key, $subject, $type, $email);
            }
          } else {
            $this->sendMessage($webhook, $channel, $project, $event_name, $event_data, $api_key, $subject, $type, $email);
          }
        }
    }

    /**
     * Get message to send
     *
     * @access public
     * @param  array     $project
     * @param  string    $event_name
     * @param  array     $event_data
     * @param  string    $channel
     * @param  string    $subject
     * @param  string    $type
     * @param  string    $email
     * @return array
     */
    public function getMessage(array $project, $event_name, array $event_data, $channel, $subject, $type, $email)
    {
        if ($this->userSession->isLogged()) {
            $author = $this->helper->user->getFullname();
            $title = $this->notificationModel->getTitleWithAuthor($author, $event_name, $event_data);
        } else {
            $title = $this->notificationModel->getTitleWithoutAuthor($event_name, $event_data);
        }

        $message = '**['.$project['name']."]** ";

        if ($this->configModel->get('application_url') !== '') {
            $message .= t("[".$event_data['task']['title']."]");
            $message .= '(';
            $message .= $this->helper->url->to('TaskViewController', 'show', array('task_id' => $event_data['task']['id'], 'project_id' => $project['id']), '', true);
            $message .= ')'."\n";
        }

        $message .= $title."\n";
        
        // Standardize type to use modern Zulip API conventions
        $type = strtolower($type);
        if ($type === 'private') {
            $type = 'direct'; // Map deprecated 'private' to modern 'direct'
        } elseif ($type === 'stream') {
            $type = 'channel'; // Map deprecated 'stream' to modern 'channel'
        }
        
        if ($type === 'direct') {
            // Ensure email is in array format for direct messages
            $payload = array(
                'type' => 'direct',
                'to' => !is_array($email) ? [$email] : $email,
                'content' => $message,
            );
        } else {
            // Default to channel type
            $payload = array(
                'type' => 'channel',
                'to' => $channel,
                'topic' => $subject, // Using 'topic' as per current Zulip API
                'content' => $message,
            );
        }
        
        return $payload;
    }

    /**
     * Send message to Zulip
     *
     * @access private
     * @param  string    $webhook
     * @param  string    $channel
     * @param  array     $project
     * @param  string    $event_name
     * @param  array     $event_data
     * @param  string    $api_key
     * @param  string    $subject
     * @param  string    $type
     * @param  string    $email
     */
    private function sendMessage($webhook, $channel, array $project, $event_name, array $event_data, $api_key, $subject, $type, $email)
    {
        $payload = $this->getMessage($project, $event_name, $event_data, $channel, $subject, $type, $email);
        
        // Properly handle API key for Zulip authentication
        $headers = array();
        if (!empty($api_key)) {
            $headers[] = 'Authorization: Basic ' . base64_encode($api_key);
        }

        $this->httpClient->postFormAsync($webhook, $payload, $headers);
    }
}

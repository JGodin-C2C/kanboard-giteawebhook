<?php
namespace Kanboard\Plugin\GiteaWebhook;

use Kanboard\Core\Base;
use Kanboard\Event\GenericEvent;

/**
 * Gitea Webhook
 *
 * @author   Frederic Guillot
 */
class WebhookHandler extends Base
{
    /**
     * Events
     *
     * @var string
     */
    const EVENT_COMMIT                = 'gitea.webhook.commit';
    const EVENT_ISSUE_OPENED          = 'gitea.webhook.issue.opened';
    const EVENT_ISSUE_CLOSED          = 'gitea.webhook.issue.closed';
    const EVENT_ISSUE_REOPENED        = 'gitea.webhook.issue.reopened';
    const EVENT_ISSUE_COMMENT         = 'gitea.webhook.issue.commented';
    const EVENT_ISSUE_ASSIGNEE_CHANGE = 'gitea.webhook.issue.assignee';

    /**
     * Supported webhook events
     *
     * @var string
     */
    const TYPE_PUSH    = 'push';
    const TYPE_ISSUE   = 'issues';
    const TYPE_ISSUE_COMMENT = 'issue_comment';
    const TYPE_COMMENT = 'comment';

    /**
     * Project id
     *
     * @access private
     * @var integer
     */
    private $project_id = 0;

    /**
     * Set the project id
     *
     * @access public
     * @param  integer   $project_id   Project id
     */
    public function setProjectId($project_id)
    {
        $this->project_id = $project_id;
    }

    /**
     * Parse incoming events
     *
     * @access public
     * @param  string  $type      Gitea event type
     * @param  array   $payload   Gitea event
     * @return boolean
     */
    public function parsePayload($type, array $payload)
    {

        switch ($type) {
            case self::TYPE_PUSH:
                return $this->handlePush($payload);
            case self::TYPE_ISSUE:
                return $this->handleIssueEvent($payload);
            case self::TYPE_ISSUE_COMMENT:
            case self::TYPE_COMMENT:
                return $this->handleCommentEvent($payload);
        }

        return false;
    }

    /**
     * Parse push events
     *
     * @access public
     * @param  array   $payload
     * @return boolean
     */
    public function handlePush(array $payload)
    {
        $results = array();

        if (isset($payload['commits'])) {
            foreach ($payload['commits'] as $commit) {
                $results[] = $this->handleCommit($commit);
            }
        }

        return in_array(true, $results, true);
    }

    /**
     * Parse commit
     *
     * @access public
     * @param  array   $commit   Gitea commit
     * @return boolean
     */
    public function handleCommit(array $commit)
    {
        $task_id = $this->taskModel->getTaskIdFromText($commit['message']);

        if (empty($task_id)) {
            return false;
        }

        $task = $this->taskFinderModel->getById($task_id);

        if (empty($task)) {
            return false;
        }

        if ($task['project_id'] != $this->project_id) {
            return false;
        }

        $authorName = '';
        if (! empty($commit['author']['name'])) {
            $authorName = $commit['author']['name'];
        } elseif (! empty($commit['author']['username'])) {
            $authorName = $commit['author']['username'];
        }

        $commitUrl = isset($commit['url']) ? $commit['url'] : '';

        $this->dispatcher->dispatch(
            new GenericEvent(array(
                'task_id' => $task_id,
                'commit_message' => $commit['message'],
                'commit_url' => $commitUrl,
                'comment' => $commit['message']."\n\n[".t('Commit made by @%s on Gitea', $authorName).']('.$commitUrl.')',
            ) + $task),
            self::EVENT_COMMIT
        );

        return true;
    }

    /**
     * Parse issue event
     *
     * @access public
     * @param  array   $payload   GitLab event
     * @return boolean
     */
    public function handleIssueEvent(array $payload)
    {

        switch ($payload['action']) {
            case 'opened':
                return $this->handleIssueOpened($payload['issue'], $payload['repository']);
            case 'closed':
                return $this->handleIssueClosed($payload['issue'], $payload['repository']);
            case 'reopened':
                return $this->handleIssueReopened($payload['issue'], $payload['repository']);
            case 'assigned':
                return $this->handleIssueAssigned($payload['issue'], $payload['repository']);
            case 'unassigned':
                return $this->handleIssueUnassigned($payload['issue'], $payload['repository']);
        }
        return false;
    }
    /**
     * Handle new issues
     *
     * @access public
     * @param  array    $issue   Issue data
     * @param  array    $repo    Repo info
     * @return boolean
     */
    public function handleIssueOpened(array $issue, array $repo)
    {
        if (isset($issue['html_url'])) {
            $issue_url = $issue['html_url'];
        } elseif (isset($issue['url'])) {
            $issue_url = str_replace('api/v1/repos/', '', $issue['url']);
        } else {
            $issue_url = '';
        }
        $task_reference = $repo['full_name']."#".$issue['number'];
        $description = isset($issue['body']) ? $issue['body'] : '';
        $description .= "\n\n[".t('Gitea Issue').']('.$issue_url.')';
        $event = array(
            'project_id' => $this->project_id,
            'reference' => $task_reference,
            'title' => isset($issue['title']) ? $issue['title'] : '',
            'description' => $description,
            'assignee_id' => ! empty($issue['assignee']['id']) ? $issue['assignee']['id'] : null,
            'author_id' => ! empty($issue['user']['id']) ? $issue['user']['id'] : null,
        );
        $this->dispatcher->dispatch(
            new GenericEvent($event),
            self::EVENT_ISSUE_OPENED
        );
        return true;
    }
    /**
     * Handle issue reopening
     *
     * @access public
     * @param  array    $issue   Issue data
     * @param  array    $repo    Repo info
     * @return boolean
     */
    public function handleIssueReopened(array $issue, array $repo)
    {
        $task_reference = $repo['full_name']."#".$issue['number'];
        $task = $this->taskFinderModel->getByReference($this->project_id, $task_reference);
        if (! empty($task)) {
            $event = array(
                'project_id' => $this->project_id,
                'task_id' => $task['id'],
                'reference' => $task_reference,
                'assignee_id' => isset($issue['assignee']['id']) ? $issue['assignee']['id'] : (isset($issue['assignee_id']) ? $issue['assignee_id'] : null),
                'author_id' => isset($issue['user']['id']) ? $issue['user']['id'] : (isset($issue['author_id']) ? $issue['author_id'] : null),
            );
            $this->dispatcher->dispatch(
                new GenericEvent($event),
                self::EVENT_ISSUE_REOPENED
            );
            return true;
        }
        return false;
    }
    /**
     * Handle issue closing
     *
     * @access public
     * @param  array    $issue   Issue data
     * @param  array    $repo    Repo info
     * @return boolean
     */
    public function handleIssueClosed(array $issue, array $repo)
    {
        $task_reference = $repo['full_name']."#".$issue['number'];
        $task = $this->taskFinderModel->getByReference($this->project_id, $task_reference);
        if (! empty($task)) {
            $event = array(
                'project_id' => $this->project_id,
                'task_id' => $task['id'],
                'reference' => $task_reference,
                'assignee_id' => isset($issue['assignee']['id']) ? $issue['assignee']['id'] : (isset($issue['assignee_id']) ? $issue['assignee_id'] : null),
                'author_id' => isset($issue['user']['id']) ? $issue['user']['id'] : (isset($issue['author_id']) ? $issue['author_id'] : null),
            );
            $this->dispatcher->dispatch(
                new GenericEvent($event),
                self::EVENT_ISSUE_CLOSED
            );
            return true;
        }
        return false;
    }

    /**
     * Handle issue assignee change
     *
     * @access public
     * @param  array    $issue   Issue data
     * @param  array    $repo    Repo info
     * @return boolean
     */
    public function handleIssueAssigned(array $issue, array $repo)
    {
        if (empty($issue['assignee'])) {
            return false;
        }

        $task_reference = $repo['full_name']."#".$issue['number'];
        $username = isset($issue['assignee']['login']) ? $issue['assignee']['login'] : (isset($issue['assignee']['username']) ? $issue['assignee']['username'] : '');
        $user = ! empty($username) ? $this->userModel->getByUsername($username) : null;
        $task = $this->taskFinderModel->getByReference($this->project_id, $task_reference);
        if (! empty($user) && ! empty($task) && $this->projectPermissionModel->isAssignable($this->project_id, $user['id'])) {
            $event = array(
                'project_id' => $this->project_id,
                'task_id' => $task['id'],
                'owner_id' => $user['id'],
                'reference' => $task_reference,
            );
            $this->dispatcher->dispatch(
                new GenericEvent($event),
                self::EVENT_ISSUE_ASSIGNEE_CHANGE
            );
            return true;
        }
        return false;
    }
    /**
     * Handle unassigned issue
     *
     * @access public
     * @param  array    $issue   Issue data
     * @param  array    $repo    Repo info
     * @return boolean
     */
    public function handleIssueUnassigned(array $issue, array $repo)
    {
        $task_reference = $repo['full_name']."#".$issue['number'];
        $task = $this->taskFinderModel->getByReference($this->project_id, $task_reference);
        if (! empty($task)) {
            $event = array(
                'project_id' => $this->project_id,
                'task_id' => $task['id'],
                'owner_id' => 0,
                'reference' => $task_reference,
            );
            $this->dispatcher->dispatch(
                new GenericEvent($event),
                self::EVENT_ISSUE_ASSIGNEE_CHANGE
            );
            return true;
        }
        return false;
    }

    /**
     * Parse comment issue events
     *
     * @access public
     * @param  array   $payload   Event data
     * @return boolean
     */
    public function handleCommentEvent(array $payload)
    {
        if (! isset($payload['issue']) || ! isset($payload['repository'])) {
            return false;
        }
        $task_reference = $payload['repository']['full_name']."#".$payload['issue']['number'];
        $task = $this->taskFinderModel->getByReference($this->project_id, $task_reference);
        if (! empty($task)) {
            $username = '';
            if (! empty($payload['comment']['user']['username'])) {
                $username = $payload['comment']['user']['username'];
            } elseif (! empty($payload['comment']['user']['login'])) {
                $username = $payload['comment']['user']['login'];
            } elseif (! empty($payload['user']['username'])) {
                $username = $payload['user']['username'];
            }

            $user = ! empty($username) ? $this->userModel->getByUsername($username) : array();
            if (! empty($user) && ! $this->projectPermissionModel->isAssignable($this->project_id, $user['id'])) {
                $user = array();
            }
            $comment = isset($payload['comment']['body']) ? $payload['comment']['body'] : '';
            $commentAuthor = ! empty($username) ? $username : 'unknown';
            $commentUrl = isset($payload['comment']['html_url']) ? $payload['comment']['html_url'] : '';
            $comment .= "\n\n[".t('By @%s on Gitea', $commentAuthor).']('.$commentUrl.')';
            $event = array(
                'project_id' => $this->project_id,
                'reference' => $task_reference,
                'comment' => $comment,
                'user_id' => ! empty($user) ? $user['id'] : 0,
                'task_id' => $task['id'],
            );
            $this->dispatcher->dispatch(
                new GenericEvent($event),
                self::EVENT_ISSUE_COMMENT
            );
            return true;
        }
        return false;
    }
}

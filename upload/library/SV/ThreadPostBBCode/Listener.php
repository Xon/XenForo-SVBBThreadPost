<?php

class SV_ThreadPostBBCode_Listener
{
    public static function getModelFromCache($class)
    {
        static $_modelCache = array();

        if (!isset($_modelCache[$class]))
        {
            $_modelCache[$class] = XenForo_Model::create($class);
        }

        return $_modelCache[$class];
    }

    // cache for the entire lifetime of the request, this permits edits to not be insane
    static $postCache = array();
    static $threadCache = array();

    public static function bbcodeThreadPostPreCache(array &$preCache, array &$rendererStates, $formatterName)
    {
        if(empty($preCache['sv_LinkPostIds']) && empty($preCache['sv_LinkThreadIds']))
        {
            return;
        }

        $visitor = XenForo_Visitor::getInstance();
        $visitorArr = $visitor->toArray();

        $postModel = self::getModelFromCache('XenForo_Model_Post');
        $threadModel = self::getModelFromCache('XenForo_Model_Thread');
        $forumModel = self::getModelFromCache('XenForo_Model_Forum');

        $forumPermCheck = array();
        $threadPermCheck = array();

        $nodesPerm = null;
        $posts = array();
        if (!empty($preCache['sv_LinkPostIds']))
        {
            $postIds = XenForo_Application::arrayColumn(self::$postCache, 'post_id');
            $postIds = array_diff(array_unique($preCache['sv_LinkPostIds']), $postIds);
            $posts = $postModel->getPostsByIds($postIds, array(
                'join' => XenForo_Model_Post::FETCH_THREAD | XenForo_Model_Post::FETCH_FORUM
            ));
            $errorPhraseKey = '';

            foreach($posts as $postId => &$post)
            {
                $nodeId = $post['node_id'];
                $threadId = $post['thread_id'];
                $nodePermissions = $visitor->getNodePermissions($nodeId);

                // only check forums/threads once
                if (!isset($forumPermCheck[$nodeId]))
                {
                    $forumPermCheck[$nodeId] = $forumModel->canViewForum($post, $errorPhraseKey, $nodePermissions, $visitorArr);
                }
                if (!isset($threadPermCheck[$threadId]))
                {
                    $threadPermCheck[$threadId] = $threadModel->canViewThread($post, $post, $errorPhraseKey, $nodePermissions, $visitorArr);
                }

                if (!$forumPermCheck[$nodeId] ||
                    !$threadPermCheck[$threadId] ||
                    !$postModel->canViewPost($post, $post, $post, $errorPhraseKey, $nodePermissions, $visitorArr))
                {
                    $post = array('post_id' => $postId);
                }
                else
                {
                    // do not keep the message
                    unset($post['message']);
                    unset($post['message_parsed']);
                    self::$threadCache[$post['thread_id']] = $post;
                }
                self::$postCache[$postId] = $post;
            }
            // negative lookup caching
            foreach($postIds as $postId)
            {
                if (!isset(self::$postCache[$postId]))
                {
                    self::$postCache[$postId] = array('post_id' => $postId);
                }
            }
        }

        if (!empty($preCache['sv_LinkThreadIds']))
        {
            $threadIds = array_unique(XenForo_Application::arrayColumn(self::$threadCache, 'thread_id'));
            $threadIds = array_diff(array_unique($preCache['sv_LinkThreadIds']), $threadIds);
            $threads = $threadModel->getThreadsByIds($threadIds, array(
                'join' => XenForo_Model_Thread::FETCH_FORUM
            ));

            foreach($threads as $threadId => &$thread)
            {
                $nodeId = $thread['node_id'];
                $nodePermissions = $visitor->getNodePermissions($nodeId);

                // only check forums/threads once
                if (!isset($forumPermCheck[$nodeId]))
                {
                    $forumPermCheck[$nodeId] = $forumModel->canViewForum($thread, $errorPhraseKey, $nodePermissions, $visitorArr);
                }

                if (!$forumPermCheck[$nodeId] ||
                    !$threadModel->canViewThread($thread, $thread, $errorPhraseKey, $nodePermissions, $visitorArr))
                {
                    $thread = array('thread_id' => $threadId);
                }
                self::$threadCache[$threadId] = $thread;
            }
            // negative lookup caching
            foreach($threadIds as $threadId)
            {
                if (!isset(self::$threadCache[$threadId]))
                {
                    self::$threadCache[$threadId] = array('thread_id' => $threadId);
                }
            }
        }
    }

    public static function bbcodeThread(array $tag, array $rendererStates, &$parentClass )
    {
        $thread_id = $tag['option'];
        // precaching
        if(!empty($rendererStates['bbmPreCacheInit']))
        {
            $parentClass->pushBbmPreCacheData('sv_LinkThreadIds', $thread_id);
            return;
        }

        $thread = array('thread_id' => $thread_id);

        // Get data section
        if(isset(self::$threadCache[$thread_id]))
        {
            $thread = self::$threadCache[$thread_id];
        }
        else if ($parentClass->getView() !== null)
        {
            $threadModel = self::getModelFromCache('XenForo_Model_Thread');
            $forumModel = self::getModelFromCache('XenForo_Model_Forum');

            $foundThread = $threadModel->getThreadById($thread_id, array(
                'join' => XenForo_Model_Thread::FETCH_FORUM
            ));

            if ($foundThread)
            {
                $errorPhraseKey = '';
                $visitor = XenForo_Visitor::getInstance();
                $visitorArr = $visitor->toArray();
                $nodePermissions = $visitor->getNodePermissions($foundThread['node_id']);
                if ($forumModel->canViewForum($foundThread, $errorPhraseKey, $nodePermissions, $visitorArr) &&
                    $threadModel->canViewThread($foundThread, $foundThread, $errorPhraseKey, $nodePermissions, $visitorArr))
                {
                    $thread = $foundThread;
                }
            }
            self::$threadCache[$thread_id] = $thread;
        }

        $link = XenForo_Link::buildPublicLink('threads', $thread);

        return '<a href="' . $link . '" class="internalLink">' . $parentClass->renderSubTree($tag['children'], $rendererStates) . '</a>';
    }

    public static function bbcodePost(array $tag, array $rendererStates, &$parentClass )
    {
        $post_id = $tag['option'];
        // precaching
        if(!empty($rendererStates['bbmPreCacheInit']))
        {
            $parentClass->pushBbmPreCacheData('sv_LinkPostIds', $post_id);
            return;
        }

        $post = array('post_id' => $post_id);

        // Get data section
        if(isset(self::$postCache[$post_id]))
        {
            $thread = self::$postCache[$post_id];
        }
        else if ($parentClass->getView() !== null)
        {
            $postModel = self::getModelFromCache('XenForo_Model_Post');
            $threadModel = self::getModelFromCache('XenForo_Model_Thread');
            $forumModel = self::getModelFromCache('XenForo_Model_Forum');

            $foundPost = $postModel->getPostById($post_id, array(
                'join' => XenForo_Model_Post::FETCH_THREAD | XenForo_Model_Post::FETCH_FORUM
            ));

            if ($foundPost)
            {
                $errorPhraseKey = '';
                $visitor = XenForo_Visitor::getInstance();
                $visitorArr = $visitor->toArray();
                $nodePermissions = $visitor->getNodePermissions($foundPost['node_id']);
                if ($forumModel->canViewForum($foundPost, $errorPhraseKey, $nodePermissions, $visitorArr) &&
                    $threadModel->canViewThread($foundPost, $foundPost, $errorPhraseKey, $nodePermissions, $visitorArr) &&
                    $postModel->canViewPost($foundPost, $foundPost, $foundPost, $errorPhraseKey, $nodePermissions, $visitorArr))
                {
                    $post = $foundPost;
                }
            }
            self::$postCache[$post_id] = $post;
        }

        if (isset($post['thread_id']))
        {
            static $messagesPerPage = null;
            if ($messagesPerPage == null)
            {
                $messagesPerPage = XenForo_Application::get('options')->messagesPerPage;
            }
            $page = floor($post['position']/$messagesPerPage)+1;
            $link = XenForo_Link::buildPublicLink('threads', $post, array('page' => $page)) . '#post-' . $post['post_id'];
        }
        else
        {
            $link = XenForo_Link::buildPublicLink('posts', $post);
        }

        return '<a href="' . $link . '" class="internalLink">' . $parentClass->renderSubTree($tag['children'], $rendererStates) . '</a>';
    }
}
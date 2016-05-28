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
    static $cachekeyBase = 'postthreadmap_';
    public static $cachekey = null;
    static $postCache = null;
    static $threadCache = array();

    public static function bbcodeThreadPostPreCache(array &$preCache, array &$rendererStates, $formatterName)
    {
        if(empty($preCache['sv_LinkPostIds']) && empty($preCache['sv_LinkThreadIds']))
        {
            return;
        }

        $visitor = XenForo_Visitor::getInstance();
        $visitorArr = $visitor->toArray();

        $db = XenForo_Application::getDb();


        $cache = XenForo_Application::getCache();

        $threshold = 1000;
        // cached post->thread mapping
        if (self::$postCache === null)
        {
            if ($cache && self::$cachekey)
            {
                $raw = $cache->load(self::$cachekeyBase.self::$cachekey);
                self::$postCache = @unserialize($raw);
            }
            if (empty(self::$postCache))
            {
                self::$postCache = array();
            }
        }

        $threads = array();
        if (!empty($preCache['sv_LinkThreadIds']))
        {
            $threads = array_fill_keys($preCache['sv_LinkThreadIds'], true);
        }
        if (!empty($preCache['sv_LinkPostIds']))
        {
            $requestedPostIds = array_unique($preCache['sv_LinkPostIds']);
            $postIds = XenForo_Application::arrayColumn(self::$postCache, 'post_id');
            $postIds = array_diff($requestedPostIds, $postIds);
            $initialCount = count(self::$postCache);

            if ($postIds)
            {
                if (count($postIds) < $threshold)
                {
                    // use a custom query to only fetch the minimum data
                    $postModel = self::getModelFromCache('XenForo_Model_Post');
                    $posts = $postModel->fetchAllKeyed('
                        SELECT post_id, thread_id, position
                        FROM xf_post
                        WHERE post_id IN (' . $db->quote($postIds) . ') and message_state = \'visible\'
                    ', 'post_id');
                    $errorPhraseKey = '';
                    // positive lookup caching
                    foreach($posts as $postId => &$post)
                    {
                        self::$postCache[$postId] = $post;
                    }
                }
                // negative lookup caching
                foreach($postIds as $postId)
                {
                    if (!isset(self::$postCache[$postId]))
                    {
                        self::$postCache[$postId] = array('post_id' => $postId);
                    }
                }

                // update cache
                if (self::$cachekey && count(self::$postCache) > $initialCount)
                {
                    $raw = serialize(self::$postCache);
                    $cache->save($raw, self::$cachekeyBase.self::$cachekey, array(), 1800);
                }
            }

            // record the requested threads
            foreach($requestedPostIds as $postId)
            {
                if (isset(self::$postCache[$postId]['thread_id']))
                {
                    $threads[self::$postCache[$postId]['thread_id']] = true;
                }
            }
        }

        if ($threads)
        {
            $threadIds = XenForo_Application::arrayColumn(self::$threadCache, 'thread_id');
            $threadIds = array_diff(array_keys($threads), $threadIds);
            if (count($threadIds) < $threshold)
            {
                static $forumPermCheck = array();
                $threadModel = self::getModelFromCache('XenForo_Model_Thread');
                $forumModel = self::getModelFromCache('XenForo_Model_Forum');
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
            $parentClass->renderSubTree($tag['children'], $rendererStates);
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

    public static function getPageForPosition($position)
    {
        static $messagesPerPage = null;
        if ($messagesPerPage == null)
        {
            $messagesPerPage = XenForo_Application::getOptions()->messagesPerPage;
        }
        return floor($post['position']/$messagesPerPage)+1;
    }

    public static function bbcodePost(array $tag, array $rendererStates, &$parentClass )
    {
        $post_id = $tag['option'];
        // precaching
        if(!empty($rendererStates['bbmPreCacheInit']))
        {
            $parentClass->pushBbmPreCacheData('sv_LinkPostIds', $post_id);
            $parentClass->renderSubTree($tag['children'], $rendererStates);
            return;
        }

        $post = array('post_id' => $post_id);

        // Get data section
        if(isset(self::$postCache[$post_id]))
        {
            $_post = self::$postCache[$post_id];
            if (isset($_post['thread_id']) && isset(self::$threadCache[$_post['thread_id']]))
            {
                $thread = self::$threadCache[$_post['thread_id']];
                $thread['post_id'] = $_post['post_id'];
                $thread['position'] = $_post['position'];
                $post = $thread;
            }
        }
        else if ($parentClass->getView() !== null)
        {
            $postModel = self::getModelFromCache('XenForo_Model_Post');

            $foundPost = $postModel->getPostById($post_id, array(
                'join' => XenForo_Model_Post::FETCH_THREAD | XenForo_Model_Post::FETCH_FORUM,
                'skip_wordcount' => true,
            ));

            if ($foundPost)
            {
                $threadModel = self::getModelFromCache('XenForo_Model_Thread');
                $forumModel = self::getModelFromCache('XenForo_Model_Forum');

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
            $page = self::getPageForPosition($post['position']);
            $link = XenForo_Link::buildPublicLink('threads', $post, array('page' => $page)) . '#post-' . $post['post_id'];
        }
        else
        {
            $link = XenForo_Link::buildPublicLink('posts', $post);
        }

        return '<a href="' . $link . '" class="internalLink">' . $parentClass->renderSubTree($tag['children'], $rendererStates) . '</a>';
    }

    public static function load_class($class, array &$extend)
    {
        $extend[] = 'SV_ThreadPostBBCode_'.$class;
    }
}
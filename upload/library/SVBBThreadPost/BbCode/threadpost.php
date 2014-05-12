<?php 

// thanks to Vorpal
class SVBBThreadPost_BbCode_threadpost
{
	public static function getModelFromCache(XenForo_BbCode_Formatter_Base $formatter, $class)
	{
        if (!isset($formatter->_modelCache))
            $formatter->_modelCache = array();
        
		if (!isset($formatter->_modelCache[$class]))
		{
			$formatter->_modelCache[$class] = XenForo_Model::create($class);
		}

		return $formatter->_modelCache[$class];
	}
    
    public static function getThread(XenForo_BbCode_Formatter_Base $formatter, $thread_id)
    {
        if (is_numeric($thread_id))
        {
            $forumModel = self::getModelFromCache($formatter, 'XenForo_Model_Forum');
            $forum = $forumModel->getForumByThreadId($thread_id);
            if ($forum && $forumModel->canViewForum($forum))
            {
                $threadModel = self::getModelFromCache($formatter, 'XenForo_Model_Thread');
                $thread = $threadModel->getThreadById($thread_id);                                
                if ($thread && $threadModel->canViewThread($thread, $forum))                    
                    return array($forum, $thread);
            }            
        }   
        return array(0,0);
    }

    public static function getPost(XenForo_BbCode_Formatter_Base $formatter, $post_id)
    {
        if (is_numeric($post_id))
        {
            $postModel = self::getModelFromCache($formatter,'XenForo_Model_Post');
            $post = $postModel->getPostById($post_id);         
            if ($post)
            {
                list ($forum, $thread) = self::getThread($formatter, $post['thread_id']);                
                if ($forum && $thread && $postModel->canViewPost($post, $thread, $forum))
                    return array($forum, $thread, $post);
            }
        }   
        return array(0,0,0);
    }

    
    public static function thread(array $tag, array $rendererStates, XenForo_BbCode_Formatter_Base $formatter) 
    {
        $thread_id = $tag['option'];        
        list ($forum, $thread) = self::getThread($formatter, $thread_id);
            
        if (!$thread)    
            $thread = array('thread_id' => intval($thread_id));
            
        return '<a href="' . XenForo_Link::buildPublicLink('threads', $thread) . '" class="internalLink">' . $formatter->renderSubTree($tag['children'],$rendererStates) . '</a>';
    }
    

    public static function post(array $tag, array $rendererStates, XenForo_BbCode_Formatter_Base $formatter) 
    {
        $post_id = $tag['option'];        
        list ($forum, $thread, $post) = self::getPost($formatter, $post_id );

        if ($post)        
        {
            $page = floor($post['position']/XenForo_Application::get('options')->messagesPerPage)+1;
            $link = XenForo_Link::buildPublicLink('threads', $thread, array('page' => $page)) . '#post-' . $post['post_id'];
        }
        else
            $link = XenForo_Link::buildPublicLink('posts', array('post_id' => $post_id));
                
        return '<a href="' . $link . '" class="internalLink">' . $formatter->renderSubTree($tag['children'], $rendererStates) . '</a>';	
    }
    
}
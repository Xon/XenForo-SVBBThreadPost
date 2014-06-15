<?php 

// thanks to Vorpal
class SV_BBCodeThreadPost_BbCode_ThreadPost
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
            if (!isset($formatter->_forumCache))
                $formatter->_forumCache = array();        
            if (!isset($formatter->_threadCache))
                $formatter->_threadCache = array();

            if (isset($formatter->_threadCache[$thread_id]))
            {
                $threadModel = $formatter->_threadCache[$thread_id][0];
                $thread = $formatter->_threadCache[$thread_id][1];           
            }
            else
            {                
                $threadModel = self::getModelFromCache($formatter, 'XenForo_Model_Thread');
                $thread = $threadModel->getThreadById($thread_id);
                
                $formatter->_threadCache[$thread_id] = array($threadModel, $thread );
            }
            if ($thread)
            {
                $forum_id = $thread['forum_id'];
                if (isset($formatter->_forumCache[$forum_id]))
                {
                    $forumModel = $formatter->_threadCache[$forum_id][0];
                    $forum = $formatter->_threadCache[$forum_id][1];             
                }
                else
                {
                    $forumModel = self::getModelFromCache($formatter, 'XenForo_Model_Forum');
                    $forum = $forumModel->getForumByThreadId($thread_id);
                    
                    $formatter->_forumCache[$forum_id] = array($forumModel,$forum );
                }
            }
            if ($forum)
            if ($forumModel->canViewForum($forum) && $threadModel->canViewThread($thread, $forum))                 
                return array($forum, $thread);          
        }   
        return array(0,0);
    }

    public static function getPost(XenForo_BbCode_Formatter_Base $formatter, $post_id)
    {
        if (is_numeric($post_id))
        {
            if (!isset($formatter->_postCache))
                $formatter->_postCache = array();

            if (isset($formatter->_postCache[$post_id]))
            {
                $threadModel = $formatter->_postCache[$post_id][0];
                $thread = $formatter->_postCache[$post_id][1];           
            }
            else
            {                
                $postModel = self::getModelFromCache($formatter,'XenForo_Model_Post');
                $post = $postModel->getPostById($post_id);  
                
                $formatter->_postCache[$post_id] = array($postModel, $post );
            }                
       
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
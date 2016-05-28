<?php

class SV_ThreadPostBBCode_XenForo_BbCode_Formatter_Base extends XFCP_SV_ThreadPostBBCode_XenForo_BbCode_Formatter_Base
{
	public function setView(XenForo_View $view = null)
	{
        $params = $view->getParams();
        $thread_id = null;
        if (isset($params['post']))
        {
            $thread_id = $params['post']['thread_id'];
            $page = SV_ThreadPostBBCode_Listener::getPageForPosition($params['post']['position']);
        }
        else if (isset($params['thread']))
        {
            $thread_id = $params['thread']['thread_id'];
            $page = isset($params['page']) ? $params['page'] : 0;
        }

        if ($thread_id)
        {
            SV_ThreadPostBBCode_Listener::$cachekey = 'thread_'.$thread_id.'_page_'.$page;
        }

        return parent::setView($view);
	}
}
<?php

class SV_ThreadPostBBCode_Installer
{
    public static function install($installedAddon, array $addonData, SimpleXMLElement $xml)
    {
        $db = XenForo_Application::get('db');

        $addonModel = XenForo_Model::create("XenForo_Model_AddOn");
        $addonsToUninstall = array('SVBBThreadPost');
        foreach($addonsToUninstall as $addonToUninstall)
        {
            $addon = $addonModel->getAddOnById($addonToUninstall);
            if (!empty($addon))
            {
                $dw = XenForo_DataWriter::create('XenForo_DataWriter_AddOn');
                $dw->setExistingData($addonToUninstall);
                $dw->delete();
            }
        }

        // truncate the bbcode cache table instead of slowly deleting bits
        $db->query('
            TRUNCATE TABLE xf_bb_code_parse_cache
        ');
    }

    public static function uninstall()
    {
        $db = XenForo_Application::get('db');
        $db->query('
            TRUNCATE TABLE xf_bb_code_parse_cache
        ');
    }
}

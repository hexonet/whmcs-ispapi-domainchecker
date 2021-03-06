<?php

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\ispapidomaincheck\DCHelper;
use WHMCS\Config\Setting;
use WHMCS\Module\Addon\ispapidomaincheck\Admin\AdminDispatcher;
use WHMCS\Module\Addon\ispapidomaincheck\Client\ClientDispatcher;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once(implode(DIRECTORY_SEPARATOR, [__DIR__, "lib", "Common", "DCHelper.class.php"]));

/*
 * Configuration of the addon module.
 */
function ispapidomaincheck_config()
{
    return [
        "name" => "ISPAPI HP DomainChecker",
        "description" => "This addon provides a new domainchecker interface with high speed checks, suggestions and premium support.",
        "version" => "14.0.3",
        "author" => "HEXONET",
        "language" => "english"
    ];
}

/*
 * This function will be called with the activation of the add-on module.
 */
function ispapidomaincheck_activate()
{
    include(implode(DIRECTORY_SEPARATOR, [dirname(__FILE__),"categories.php"]));

    //if not existing, create ispapi_tblcategories table
    DCHelper::SQLCall(
        "CREATE TABLE IF NOT EXISTS ispapi_tblcategories (id INT(10) NOT NULL PRIMARY KEY AUTO_INCREMENT, name TEXT, tlds TEXT) CHARACTER SET utf8 COLLATE utf8_unicode_ci",
        null,
        "execute"
    );

    //import the default categories when empty
    $data = DCHelper::SQLCall("SELECT * FROM ispapi_tblcategories", null, "fetchall");
    if (empty($data)) {
        foreach ($categorieslib as $category => &$tlds) {
            DCHelper::SQLCall(
                "INSERT INTO ispapi_tblcategories ({{KEYS}}) VALUES ({{VALUES}})",
                [
                    ":name" => $category,
                    ":tlds" => implode(" ", $tlds)
                ],
                "execute"
            );
        }
    }
    return [
        'status'        =>  'success',
        'description'   =>  'The ISPAPI HP DomainChecker was successfully installed.'
    ];
}

/*
 * This function will update the database when upgrading.
*/
function ispapidomaincheck_upgrade($vars)
{
    DCHelper::SQLCall("ALTER TABLE ispapi_tblcategories CONVERT TO CHARACTER SET utf8 COLLATE utf8_unicode_ci", null, "execute");
    if ($vars['version'] < 7.3) {
        // 1. DROP ispapi_tblaftermarketcurrencies if exists
        DCHelper::SQLCall("DROP TABLE IF EXISTS ispapi_tblaftermarketcurrencies", null, "execute");
        // 2. DROP ispapi_tblsettings if exists
        DCHelper::SQLCall("DROP TABLE IF EXISTS ispapi_tblsettings", null, "execute");
        // 3. ALTER ispapi_tblcategories
        DCHelper::SQLCall("ALTER TABLE ispapi_tblcategories DROP COLUMN parent", null, "execute");
        // This one deletes the row and does not complain if it can't.
        DCHelper::SQLCall("DELETE IGNORE FROM ispapi_tblcategories WHERE tlds=''", null, "execute");
    }
    return [
        'status'        =>  'success',
        'description'   =>  'The ISPAPI HP DomainChecker was successfully upgraded.'
    ];
}

/*
 * This function is the startpoint of the add-on module
 * <#WHMCS_URL#>/index.php?m=ispapidomaincheck
 * <#WHMCS_URL#>/mydomainchecker.php
 */
function ispapidomaincheck_clientarea($vars)
{
    add_hook('ClientAreaHeadOutput', 1, function ($vars) {
        $now = mktime();
        $wr = $vars["WEB_ROOT"];
        // bootstrap version -> $.fn.tooltip.Constructor.VERSION
        /* {*<-- user-scalable=yes if you want user to allow zoom --> */
        return <<<HTML
        <meta name="viewport" content="width=device-width, user-scalable=no"/>
        <script>const wr = "{$wr}";</script>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Common/assets/jquery.mustache.js?t={$now}"></script>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Common/assets/mustache.min.js?t={$now}"></script>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Client/assets/jquery.growl.js?t={$now}"></script>
        <link href="{$wr}/modules/addons/ispapidomaincheck/lib/Client/assets/jquery.growl.css?t={$now}" rel="stylesheet" type="text/css"/>
        <link rel="stylesheet" type="text/css" href="{$wr}/modules/addons/ispapidomaincheck/lib/Client/assets/index.css?t={$now}"/>
        <link rel="stylesheet" type="text/css" href="{$wr}/modules/addons/ispapidomaincheck/lib/Common/assets/categories.css?t={$now}"/>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Client/assets/proxy.min.js?t={$now}"></script>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Common/assets/uts46bundle.min.js?t={$now}"></script>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Client/assets/shoppingcart.js?t={$now}"></script>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Common/assets/tplmgr.js?t={$now}"></script>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Client/assets/searchresult.js?t={$now}"></script>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Client/assets/domainsearch.js?t={$now}"></script>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Client/assets/category.js?t={$now}"></script>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Client/assets/categorymgr.js?t={$now}"></script>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Client/assets/index.js?t={$now}"></script>
HTML;
    });

    //get default currency as fallback
    $_SESSION["currency"] = DCHelper::GetCustomerCurrency();

    //save the language in the session if not already set
    if (!isset($_SESSION["Language"])) {
        $_SESSION["Language"] = strtolower(Setting::getValue('Language'));
    }

    //nodata=1 -> do not return data, we just update the chosen currency in session
    //WHMCS cares automatically about handling currency when provided in request
    if (isset($_REQUEST["nodata"]) && $_REQUEST["nodata"] == 1) {
        //respond
        header('Cache-Control: no-cache, must-revalidate'); // HTTP/1.1
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
        header('Content-type: application/json; charset=utf-8');
        die(json_encode(["success" => true, "msg" => "currency updated in session"]));
        exit();
    }

    //init smarty and call admin dispatcher
    $smarty = new Smarty();
    $smarty->escape_html = true;
    $smarty->caching = false;
    $smarty->setCompileDir($GLOBALS['templates_compiledir']);
    $smarty->setTemplateDir(implode(DIRECTORY_SEPARATOR, [__DIR__, "lib", "Client", "templates"]));
    $smarty->assign($vars);

    //call the dispatcher with action and data
    $dispatcher = new ClientDispatcher();
    $r = $dispatcher->dispatch($_REQUEST['action'], $vars, $smarty);
    if ($_REQUEST['action']) {
        //send json response headers
        header('Cache-Control: no-cache, must-revalidate'); // HTTP/1.1
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
        header('Content-type: application/json; charset=utf-8');
        //do not echo as this would add template html code around!
        die(json_encode($r));
    }
    return $r;
}

/**
 * Admin Area output
 */
function ispapidomaincheck_output($vars)
{
    add_hook('AdminAreaHeadOutput', 1, function ($vars) {
        $now = mktime();
        $wr = $vars['WEB_ROOT'];
        return <<<HTML
        <script>const wr = "{$wr}";</script>
        <link rel="stylesheet" type="text/css" href="{$wr}/modules/addons/ispapidomaincheck/lib/Common/assets/jquery-ui.min.css?t={$now}"/>
        <link rel="stylesheet" type="text/css" href="{$wr}/modules/addons/ispapidomaincheck/lib/Admin/assets/index.css?t={$now}"/>
        <link rel="stylesheet" type="text/css" href="{$wr}/modules/addons/ispapidomaincheck/lib/Common/assets/categories.css?t={$now}"/>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Common/assets/jquery-ui.min.js?t={$now}"></script>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Common/assets/uts46bundle.min.js?t={$now}"></script>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Admin/assets/web-animations.min.js?t={$now}"></script>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Admin/assets/muuri.min.js?t={$now}"></script>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Common/assets/tplmgr.js?t=$now"></script>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Common/assets/jquery.mustache.js?t={$now}"></script>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Common/assets/mustache.min.js?t={$now}"></script>
        <script src="{$wr}/modules/addons/ispapidomaincheck/lib/Admin/assets/index.js?t={$now}"></script>
HTML;
    });

    //init smarty and call admin dispatcher
    $smarty = new Smarty();
    $smarty->escape_html = true;
    $smarty->caching = false;
    $smarty->setCompileDir($GLOBALS['templates_compiledir']);
    $smarty->setTemplateDir(implode(DIRECTORY_SEPARATOR, [__DIR__, "lib", "Admin", "templates"]));
    $smarty->assign($vars);
    //call the dispatcher with action and data
    $dispatcher = new AdminDispatcher();
    $r = $dispatcher->dispatch($_REQUEST['action'], $vars, $smarty);
    if ($_REQUEST['action']) {
        //send json response headers
        header('Cache-Control: no-cache, must-revalidate'); // HTTP/1.1
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
        header('Content-type: application/json; charset=utf-8');
        //do not echo as this would add template html code around!
        die(json_encode($r));
    }
    echo $r;
}

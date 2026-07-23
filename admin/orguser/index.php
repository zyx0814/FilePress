<?php
/*
 * @copyright   QiaoQiaoShiDai Internet Technology(Shanghai)Co.,Ltd
 * @license     https://www.oaooa.com/licenses/
 * 
 * @link        https://www.oaooa.com
 * @author      zyx(zyx@oaooa.com)
 */
if (!defined('IN_OAOOA') || !defined('IN_ADMIN') || !defined('PICHOME_LIENCE')) {
    @header( 'HTTP/1.1 404 Not Found' );
    @header( 'Status: 404 Not Found' );
    exit('File not found');
}
$navtitle= lang('appname');
require_once(__DIR__.'/dist/index.html');
exit();
<?php
/**
*   Class for managing notifications
*
*   @author     Lee Garner <lee@leegarner.com>
*   @copyright  Copyright (c) 2016 Lee Garner <lee@leegarner.com>
*   @package    classifieds
*   @version    1.1.0
*   @license    http://opensource.org/licenses/gpl-2.0.php
*               GNU Public License v2 or later
*   @filesource
*/

/**
*   Class for category objects
*/
class adNotify
{
    /**
    *   Send an email to all subscribers of a category or its parent
    *   when an ad is approved.
    *
    *   Email is only sent if the ad is approved and a notification
    *   hasn't already been sent.
    *
    *   @param  object  $Ad     Ad object
    *   @return boolean         True on notification, False on error
    */
    public static function Subscribers($Ad)
    {
        global $_TABLES,  $_CONF, $_CONF_ADVT;

        if ($Ad->isNew)
            return false;

        // check approval status and whether a notification was already sent.
        if ($Ad->sentnotify == 1)
            return true;

        // Collect all the parent categories into a comma-separated list, and
        // find all the subscribers in any of the categories
        USES_classifieds_class_category();
        $catlist = adCategory::ParentList($Ad->cat_id);
        $sql = "SELECT uid FROM {$_TABLES['ad_notice']}
                WHERE cat_id IN ($catlist)";
        $notice = DB_query($sql, 1);
        if (!$notice)
            return false;

        // send the notification to subscribers
        while ($row = DB_fetchArray($notice, false)) {
            $result = DB_query("SELECT username, email, language
                FROM {$_TABLES['users']}
                WHERE uid='{$row['uid']}'");
            if (DB_numRows($result) == 0)
                continue;

            $user = DB_fetchArray($result, false);

            // Select the template for the message
            $template_dir = CLASSIFIEDS_PI_PATH .
                    '/templates/notify/' . $user['language'];
            if (!file_exists($template_dir . '/subscriber.thtml')) {
                $template_dir = CLASSIFIEDS_PI_PATH . '/templates/notify/english';
            }

            // Load the recipient's language.  $LANG_ADVT is *not* global here
            // to avoid overwriting the global language strings.
            $LANG = self::loadLanguage($user['language']);

            $T = new Template($template_dir);
            $T->set_file('message', 'subscriber.thtml');

            $ad_type = adType::GetDescription($Ad->ad_type);
            $T->set_var(array(
                'cat'       => $Ad->Cat->BreadCrumbs(),
                'subject'   => $Ad->subject,
                'description' => $Ad->descript,
                'username'  => COM_getDisplayName($row['uid']),
                'ad_url'    => "{$_CONF['site_url']}/{$_CONF_ADVT['pi_name']}/index.php?mode=detail&id=$ad_id",
                'price'     => $Ad->price,
                'ad_type'   => $Ad->ad_type,
            ), false);
            $T->parse('output','message');
            $message = $T->finish($T->get_var('output'));

            COM_mail(
                array($user['email']),
                "{$LANG['new_ad_listing']} {$_CONF['site_name']}",
                $message,
                '',
                true
            );
        }

        // update the ad's flag to indicate that a notification has been sent
        DB_query("UPDATE {$_TABLES['ad_ads']}
                SET sentnotify=1
                WHERE ad_id='$ad_id'");
    }   // function Subscribers()


    /**
    *   Sends an email to the owner of an ad indicating acceptance or rejection.
    *   Note: Rejection notification is not currently supported.
    *   The language file is determined based on the owner's configured language,
    *   defaulting to English.
    *
    *   @param  object  $Ad         Approved or rejected Ad object.
    *   @param  boolean $approved   True if ad is approved, False if rejected
    */
    public static function Approval($Ad, $approved=true)
    {
        global $_TABLES, $_CONF, $_CONF_ADVT;

        // First, determine if we even notify users of this condition
        if (
            $_CONF_ADVT['emailusers'] == 0       // Never notify
            ||
            ($_CONF_ADVT['emailusers'] == 2 && !$approved)  // approval only
        )
            return;

        USES_classifieds_class_ad();
        USES_classifieds_class_adtype();
        USES_classifiecs_class_category();

        // Sanitizing this since it gets used in another query.
        $username = COM_getDisplayName($Ad->uid);
        $email = DB_getItem($_TABLES['users'], 'email', "uid='{$Ad->uid}'");

        // Include the owner's language, if possible.
        $language = DB_getItem($_TABLES['users'], 'language', "uid='{$Ad->uid}'");
        $LANG = self::loadLanguage(array($language, $_CONF['language']));

        // If approved, then the ad has already been moved to the main table.
        // Otherwise, the data is still in the submission table.
        if ($approved) {
            $template_file = 'approved.thtml';
            $subject = $LANG['subj_approved'];
        } else {
            $template_file = 'rejected.thtml';
            $subject = $LANG['subj_rejected'];
        }

        // Pick the template based on approval status and language
        $template_base = CLASSIFIEDS_PI_PATH . '/templates/notify';

        if (file_exists("$template_base/{$language}/$template_file")) {
            $template_dir = "$template_base/{$language}";
        } else {
            $template_dir = "$template_base/english";
        }

        $T = new Template($template_dir);
        $T->set_file('message', $template_file);
        $T->set_var(array(
            'username'  => $username,
            'subject'   => $Ad->subject,
            'descript'  => $Ad->descript,
            'price'     => $Ad->price,
            'cat'       => adCategory::GetDescdription($Ad->cat_id),
            'ad_type'   => adType::GetDescription($A['ad_type']),
            'ad_url'    => "{$_CONF['site_url']}/{$_CONF_ADVT['pi_name']}/index.php?mode=detail&id=$ad_id",
        ) );
        $T->parse('output','message');
        $message = $T->finish($T->get_var('output'));

        COM_mail(
            array($email, $username),
            $subject,
            $message,
            '',
            true
        );
    }


    /**
    *   Generates a notification email to all uses who have ads that
    *   will expire within the set expiration period.
    */
    public static function Expiration()
    {
        global $_TABLES, $_CONF, $_CONF_ADVT;

        $interval = intval($_CONF_ADVT['exp_notify_days']) * 3600 * 24;
        $exp_dt = time() + $interval;

        $sql = "SELECT ad.ad_id, ad.uid, u.notify_exp, us.email, us.language
            FROM {$_TABLES['ad_ads']} ad
            LEFT JOIN {$_TABLES['ad_uinfo']} u
                ON u.uid = ad.uid
            LEFT JOINE {$_TABLES['users']} us
                ON ad.uid = us.uid
            WHERE exp_sent = 0
                AND u.notify_exp = 1
                AND us.uid IS NOT NULL
                AND exp_date < $exp_dt";
        $r = DB_query($sql, 1);
        if (!$r)
            return;

        // Load all the ads and users into arrays to be cycled through once.
        $users = array();
        $ads = array();
        while ($row = DB_fetchArray($r)) {
            $ads[] = $row['ad_id'];
            if (!isset($users[$row['uid']])) {
                $users[$row['uid']] = array(
                    'ad_count'  => 1,
                    'email'     => $row['email'],
                    'language'  => $row['language'],
                );
            } else {
                $users[$row['uid']]['ad_count'] += 1;
            }
        }

        $template_base = CLASSIFIEDS_PI_PATH . '/templates/notify';

        foreach ($users as $user_id=>$info) {

            $username = COM_getDisplayName($user_id);
            //$email = DB_getItem($_TABLES['users'], 'email', "uid=$user_id");
            //$language = DB_getItem($_TABLES['users'], 'language', "uid=$user_id");

            // Include the owner's language, if possible.  Fallback to site language.
            $LANG = self::loadLanguage(array($info['language'], $_CONF['language']));

            if (file_exists("$template_base/$language/expiration.thtml")) {
                $template_dir = "$template_base/$language";
            } else {
                $template_dir = "$template_base/english";
            }

            $T = new Template($template_dir);
            $T->set_file('message', 'expiration.thtml');
            $T->set_var(array(
                'num_ads'   => $info['ad_count'],
                'username'  => $username,
                'pi_name'   => $_CONF_ADVT['pi_name'],
            ) );
            $T->parse('output','message');
            $message = $T->finish($T->get_var('output'));

            COM_mail(
                array($info['email'], $username),
                $LANG['ad_exp_notice'],
                $message,
                '',
                true
            );
        }

        // Mark that the expiration notification has been sent.
        $ad_str = "'" . implode("','", $ads) . "'";
        DB_query("UPDATE {$_TABLES['ad_ads']} SET exp_sent=1 WHERE ad_id IN ($ad_str)");
        /*foreach ($ads as $ad) {
            //DB_query("UPDATE {$_TABLES['ad_ads']} SET exp_sent=1 WHERE ad_id='$ad'");
        }*/
    }


    /**
    *   Notify the site adminstrator that an ad has been submitted.
    *
    *   @param  object  $Ad     Ad object
    */
    public static function Submission($Ad)
    {
        global $_TABLES, $LANG_ADVT, $_CONF, $_CONF_ADVT;

        // First, determine if we even notify users of this condition
        if ($_CONF_ADVT['emailadmin'] == 0)     // Never notify
            return true;

        // require a valid ad ID
        if ($A->ad_id == '')
            return false;

        USES_classifieds_class_adtype();
        USES_classifieds_class_category();

        COM_clearSpeedlimit(300,'advtnotify');
        $last = COM_checkSpeedlimit ('advtnotify');
        if ($last > 0) {
            return true;
        }

        // Select the template for the message
        $template_dir = CLASSIFIEDS_PI_PATH .
                '/templates/notify/' . $_CONF['language'];
        if (!file_exists($template_dir . '/admin.thtml')) {
            $template_dir = CLASSIFIEDS_PI_PATH . '/templates/notify/english';
        }
        $T = new Template($template_dir);
        $T->set_file('message', 'admin.thtml');
        $T->set_var(array(
            'cat'       => $Ad->Cat->BreadCrumbs(),
            'subject'   => $Ad->subject,
            'description' => $Ad->descript,
            'username'  => COM_getDisplayName(2),
            'price'     => $Ad->price,
            'ad_type'   => adType::GetDescription($A->ad_type),
        ) );
        $T->parse('output','message');
        $message = $T->finish($T->get_var('output'));

        $group_id = DB_getItem($_TABLES['groups'],'grp_id',"grp_name='classifieds Admin'");
        $groups = self::_getGroupList($group_id);
        if (empty($groups))
            return true;        // Fake success if nobody to notify

        $groupList = implode(',',$groups);

        $sql = "SELECT DISTINCT {$_TABLES['users']}.uid,username,fullname,email
                FROM {$_TABLES['group_assignments']}, {$_TABLES['users']}
                WHERE {$_TABLES['users']}.uid > 1
                AND  {$_TABLES['users']}.uid = {$_TABLES['group_assignments']}.ug_uid
                AND {$_TABLES['group_assignments']}.ug_main_grp_id IN ({$groupList})";
        $result = DB_query($sql, 1);
        if (!$result) return;

        while ($row = DB_fetchArray($result, false)) {
            if ($row['email'] != '') {
                COM_errorLog("Classifieds Submit: Sending notification email to: " .
                        $row['email'] . " - " . $row['username']);
                COM_mail(
                    array($row['email'], $row['username']),
                    "{$LANG_ADVT['you_have_new_ad']} {$_CONF['site_name']}",
                    $message,
                    array($_CONF['site_mail'], $LANG_ADVT['new_ad_notice']),
                    true
                );
            }   // if valid email

        }   // foreach administrator

        COM_updateSpeedlimit('advtnotify');
    }   // function Submission()


    /**
    *   Notify the ad owner when a new comment is posted
    *
    *   @param  object  $Ad     Ad object
    */
    public static function Comment($Ad)
    {
        global $_TABLES, $LANG_ADVT, $_USER;

        // Don't notify the owner of their own comments
        if ($Ad->uid == $_USER['uid']) return;

        // Find whether the ad owner wants to be notified of new comments
        $notify = (int)DB_getItem($_TABLES['ad_uinfo'], 'notify_comment',
                "uid = '{$Ad->uid}'");
        if ($notify > 0) {
            $res = DB_query("SELECT email, language FROM {$_TABLES['users']}
                        WHERE uid = {$Ad->uid}");
            if (!$res || DB_numRows($res) < 1) return;
            $U = DB_fetchArray($res, false);
            $LANG = self::loadLanguage($U['language']);
            $username = COM_getDisplayName($Ad->uid);
            $msg = sprintf($LANG_ADVT['comment_notification'], $Ad->subject);
            $msg .= '<br /><br />' . CLASSIFIEDS_makeURL('detail', $Ad->ad_id);
            COM_mail(
                array($U['email'], $username),
                $LANG['comment_notif_subject'],
                $msg,
                '',
                true
            );
        }
    }


    /**
    *   Get all groups that are under the requested group
    *
    *   @param  integer $basegroup  Group ID where search starts
    *   @return array   Array of group IDs
    */
    private static function _getGroupList($basegroup)
    {
        global $_TABLES;

        $to_check = array();
        $checked = array();

        array_push($to_check, $basegroup);

        while (sizeof($to_check) > 0) {
            $thisgroup = array_pop($to_check);
            if ($thisgroup > 0) {
                $result = DB_query("SELECT ug_grp_id
                    FROM {$_TABLES['group_assignments']}
                    WHERE ug_main_grp_id = $thisgroup");
                if (!$result) return $checked;
                while ($A = DB_fetchArray($result, false)) {
                    // Check this group out if not already done
                    if (!in_array($A['ug_grp_id'], $checked)) {
                        if (!in_array($A['ug_grp_id'], $to_check)) {
                            // Add this group to check for sub-groups
                            array_push($to_check, $A['ug_grp_id']);
                        }
                    }
                }
                $checked[] = $thisgroup;
            }
        }

        return $checked;
    }


    /**
    *   Loads the language array. If $requested is an array, the first valid
    *   language file is loaded. If not, the $requested language file is loaded.
    *   If $requested doesn't refer to a vailid language, then $_CONF['language']
    *   is assumed.
    *
    *   After loading the base language file, the same filename is loaded from
    *   language/custom, if available. The admin can override language strings
    *   by creating a language file in that directory.
    *
    *   @param  mixed   $deflang    A single or array of language strings
    *   @return array               $LANG_ADVT, the global language array for the plugin
    */
    public static function loadLanguage($requested='')
    {
        global $_CONF;

        // Set the language to the user's selected language, unless
        // otherwise specified.
        $languages = array();

        // Add the requested language, which may be an array or
        // a single item.
        if (is_array($requested)) {
            $languages = $requested;
        } elseif ($requested != '') {
            // If no language requested, load the site/user default
            $languages[] = $requested;
        }

        // Add the site language as a failsafe
        if (!in_array($_CONF['language'], $languages)) {
            $languages[] = $_CONF['language'];
        }
        // Final failsafe, include "english.php" whish is known to exist
        if (!in_array('english', $languages)) {
            $languages[] = 'english';
        }

        // Search the array for desired language files, in order.
        $langpath = CLASSIFIEDS_PI_PATH . '/language';
        foreach ($languages as $language) {
            if (file_exists("$langpath/$language.php")) {
                include "$langpath/$language.php";
                // Include admin-supplied overrides, if any.
                if (file_exists("$langpath/custom/$language.php")) {
                    include "$langpath/custom/$language.php";
                }
                break;
            }
        }
        return $LANG_ADVT;
    }

}

?>

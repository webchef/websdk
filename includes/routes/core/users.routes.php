<?php
/**
 * Author: Arne Gockeln, WebSDK
 * Date: 31.08.15
 */

use \WebSDK\Database;
use \WebSDK\DBTables;
use \WebSDK\FlashStatus;
use \WebSDK\Pagination;
use \WebSDK\User;
use \WebSDK\UserSession;
use \WebSDK\UserTypeEnum;
use \WebSDK\UserRightEnum;

global $app;

/**
 * User Management
 */
$app->group('/users', 'isUserOnline', function() use($app){
    $app->get('/', 'route_get_users')->name('users');
    $app->post('/', 'route_get_users');
    $app->get('/add', 'route_get_users_change')->name('users_add');
    $app->get('/:page', 'route_get_users')->name('users_pagination');
    $app->get('/lock/:id', 'route_get_users_lock')->name('users_lock');
    $app->get('/unlock/:id', 'route_get_users_unlock')->name('users_unlock');
    $app->get('/edit/:id', 'route_get_users_change')->name('users_edit');
    $app->get('/delete_confirm/:id', 'route_get_users_delete_confirm')->name('users_delete_confirm');
    $app->get('/delete/:id', 'route_get_users_delete')->name('users_delete');
    $app->post('/save', 'route_post_users_save');
});

/**
 * Show users list
 * @param int $page
 */
function route_get_users($page = 0){
    // Security Check, redirect to index if false!
    isUserType(UserTypeEnum::ADMINISTRATOR, 'index');
    isUserAuthorised(UserRightEnum::MANAGE_USERS, 'index');

    $data = array();
    $app = \Slim\Slim::getInstance();
    $sql = "SELECT * FROM " . DBTables::USERS . " u WHERE u.deleted = 0";
    $mysql = Database::getInstance();

    // Filter
    if($app->request->isPost()){
        $postVars = $app->request->post();
        $inputFilterReset = getValue($postVars, 'inputFilterReset');
        if($inputFilterReset == 1){
            clearFormData();
        } else {
            $inputFilterAccountType = getValue($postVars, 'inputFilterAccountType');
            $inputFilterUsername = getValue($postVars, 'inputFilterUsername');
            $inputFilterEmail = getValue($postVars, 'inputFilterEmail');
            if (!is_empty($inputFilterAccountType) && isSecureString($inputFilterAccountType) && $inputFilterAccountType > -1) {
                $sql .= " AND u.type = '" . $mysql->getEscapedString($inputFilterAccountType) . "'";
            }
            if (!is_empty($inputFilterUsername) && isSecureUsername($inputFilterUsername)) {
                $sql .= " AND u.username LIKE '%" . $mysql->getEscapedString($inputFilterUsername) . "%'";
            }
            if (!is_empty($inputFilterEmail) && isSecureEmail($inputFilterEmail)) {
                $sql .= " AND u.email LIKE '%" . $mysql->getEscapedString($inputFilterEmail) . "%'";
            }

            // Pre-select values
            $data['filter'] = extractByKeys($postVars, 'inputFilter');

            saveFormData($data['filter']);
        }
    } else {
        // Clear only formdata if there are no filters
        $formData = restoreFormData();
        $filterData = extractByKeys($formData, 'inputFilter');
        if(is_empty($filterData)){
            clearFormData();
        } else {
            $data['filter'] = $filterData;
        }
    }

    $mysql->query($sql . " ORDER BY u.email");

    $pagination = new Pagination($mysql, $page);
    $pagination->setBaseRoute($app->urlFor('users_pagination', array('page' => '%s')));

    $data['users'] = $mysql->fetchList(true);
    $data['pagination'] = $pagination->toArray();
    $data['accountTypes'] = array();
    foreach(UserTypeEnum::getNames() as $int => $name){
        $data['accountTypes'][$int] = array('id' => $int, 'name' => $name);
    }

    renderTemplate('core/users.twig', $data);
}

/**
 * Show user change form
 * @param $id
 */
function route_get_users_change($id = 0){
    // Security Check, redirect to index if false!
    isUserType(UserTypeEnum::ADMINISTRATOR, 'index');
    // Security Check, redirect to index if false!
    isUserAuthorised(UserRightEnum::MANAGE_USERS, 'index');

    $data = array('pagetitle' => ($id > 0 ? _('Benutzer ändern') : _('Neuer Benutzer')));

    // restore session form data
    $formData = restoreFormData();
    if(hasElements($formData, array('inputUsername'))){
        parseFormData($formData);
        $data['user'] = $formData;
    } else {
        $data['user'] = classToArray(new User($id));
    }

    // get user rights with descriptions
    $data['userrights'] = UserRightEnum::getDescription();
    // show right list only for administrator account types!!
    $data['showRightList'] = getValue($data['user'], 'type') == UserTypeEnum::ADMINISTRATOR;
    // get user account types
    $data['usertypes'] = UserTypeEnum::getNames();

    renderTemplate('core/users_change.twig', $data);
}

/**
 * Show user delete confirm dialog
 * @param $id
 */
function route_get_users_delete_confirm($id){
    $message = '';
    try {
        // Security Check, redirect to index if false!
        if(!isUserType(UserTypeEnum::ADMINISTRATOR)) throw new Exception(_('Sie müssen Administrator sein!'));
        if(!isUserAuthorised(UserRightEnum::MANAGE_USERS)) throw new Exception(_('Sie benötigen mehr Rechte um diese Aktion durchzuführen!'));

        if($id <= 0){
            throw new Exception(_('ID ist null oder kleiner als 0!'));
        }

        $element = new User($id);
        $name = $element->getUsername();
        if(!is_empty($element->getFirstname()) || !is_empty($element->getLastname())){
            $name .= ", " . $element->getFirstname() . " " . $element->getLastname();
        }
        $message = sprintf(_('Soll der Benutzer "%s" wirklich gelöscht werden?'), $name);
    } catch(Exception $e){
        ajaxResponse($e->getMessage());
    }

    ajaxResponse($message);
}

/**
 * Delete user
 * @param $id
 */
function route_get_users_delete($id){
    // Security Check, redirect to index if false!
    isUserType(UserTypeEnum::ADMINISTRATOR, 'index');
    isUserAuthorised(UserRightEnum::MANAGE_USERS, 'index');

    try {
        if($id <= 0){
            throw new Exception(_('ID ist null oder kleiner als 0!'));
        }

        if(UserSession::getValue('uid') == $id){
            throw new Exception(_('Sie können sich nicht selbst löschen!'));
        }

        $user = new User($id);
        if(!$user->delete()){
            throw new Exception(_('Das hat nicht geklappt!'));
        }
    } catch(Exception $e){
        flashRedirect('users', $e->getMessage());
    }

    flashRedirect('users', _('Benutzer gelöscht!'), FlashStatus::SUCCESS);
}

/**
 * Lock user per direct link
 * @param $id
 */
function route_get_users_lock($id){
    // Security Check, redirect to index if false!
    isUserType(UserTypeEnum::ADMINISTRATOR, 'index');
    isUserAuthorised(UserRightEnum::MANAGE_USERS, 'index');

    try {
        if($id <= 0){
            throw new Exception(_('ID ist null oder kleiner als 0'));
        }

        if(UserSession::getValue('uid') == $id){
            throw new Exception(_('Sie können sich nicht selbst sperren!'));
        }

        $user = new User($id);
        $user->setLocked(1);
        if(!$user->save()){
            throw new Exception(_('Das hat nicht geklappt!'));
        }

        // send mail
        $body = renderTemplate('email/email_body_user_locked.twig', array('username' => $user->getUsername()), true);
        mailTo(array(
            'subscriber' => $user->getEmail(),
            'subject' => _('Ihr Konto wurde gesperrt!'),
            'body' => nl2br($body),
            'altbody' => strip_tags($body)
        ));
    } catch(Exception $e){
        flashRedirect('users', $e->getMessage());
    }

    flashRedirect('users', _('Benutzer gesperrt!'), FlashStatus::SUCCESS);
}

/**
 * Unlock user per direct link
 * @param $id
 */
function route_get_users_unlock($id){
    // Security Check, redirect to index if false!
    isUserType(UserTypeEnum::ADMINISTRATOR, 'index');
    isUserAuthorised(UserRightEnum::MANAGE_USERS, 'index');

    try {
        if($id <= 0){
            throw new Exception(_('ID ist null oder kleiner als 0'));
        }

        // this case should never be happen!
        if(UserSession::getValue('uid') == $id){
            throw new Exception(_('Sie können sich nicht selbst entsperren!'));
        }

        $user = new User($id);
        $user->setLocked(0);

        $newpassword = getRandomPassword();
        $salt = getSalt(10);
        $user->setPwd(hash(CFG_PASSWORD_HASH_ALGO, $newpassword . $salt));
        $user->setSalt($salt);

        if(!$user->save()){
            throw new Exception(_('Das hat nicht geklappt!'));
        }

        $body = renderTemplate('email/email_body_user_unlocked.twig', array('username' => $user->getUsername(), 'newpassword' => $newpassword), true);
        mailTo(array(
            'subscriber' => $user->getEmail(),
            'subject' => _('Ihr Konto wurde freigeschaltet!'),
            'body' => nl2br($body),
            'altbody' => strip_tags($body)
        ));
    } catch(Exception $e){
        flashRedirect('users', $e->getMessage());
    }

    flashRedirect('users', _('Benutzer freigeschaltet!'), FlashStatus::SUCCESS);
}

/**
 * Save user data
 */
function route_post_users_save(){
    // Security Check, redirect to index if false!
    isUserType(UserTypeEnum::ADMINISTRATOR, 'index');
    isUserAuthorised(UserRightEnum::MANAGE_USERS, 'index');

    $app = \Slim\Slim::getInstance();
    $postVars = $app->request->post();
    $inputId = getValue($postVars, 'inputID');
    $route = 'users_edit';
    if($inputId <= 0){
        $inputId = 0;
        $route = 'users_add';
    }

    try {
        saveFormData($postVars);

        $inputType = getValue($postVars, 'inputType');
        $inputUsername = getValue($postVars, 'inputUsername');
        $inputFirstname = getValue($postVars, 'inputFirstname');
        $inputLastname = getValue($postVars, 'inputLastname');
        $inputEmail = getValue($postVars, 'inputEmail');
        $inputLocked = getValue($postVars, 'inputLocked');
        $inputPwd1 = getValue($postVars, 'inputPwd1');
        $inputPwd2 = getValue($postVars, 'inputPwd2');
        $inputRights = getValue($postVars, 'inputRights');

        $unsecureStringLocale = getOption('default_msg_unsecure_string');

        if(is_empty($inputUsername)){
            throw new Exception(_('Der Benutzername wird benötigt!'));
        }

        if(!isSecureUsername($inputUsername)){
            throw new Exception(sprintf($unsecureStringLocale, _('Benutzername')));
        }

        if(is_empty($inputEmail)){
            throw new Exception(_('Die E-Mail Adresse wird benötigt!'));
        }
        if(!isSecureEmail($inputEmail)){
            throw new Exception(_('Die E-Mail Adresse ist nicht gültig!'));
        }

        if(!is_empty($inputFirstname)){
            if(!isSecureString($inputFirstname)) throw new Exception(sprintf($unsecureStringLocale, _('Vorname')));
        }
        if(!is_empty($inputLastname)){
            if(!isSecureString($inputLastname)) throw new Exception(sprintf($unsecureStringLocale, _('Nachname')));
        }

        if($inputLocked == 1 && UserSession::getValue('uid') == $inputId){
            throw new Exception(_('Sie können sich nicht selbst sperren!'));
        }

        // check rights
        if($inputType == UserTypeEnum::ADMINISTRATOR){
            convertArrayValueToInt($inputRights);
            $inputRights = implode(',', $inputRights);

            if(!isSecureString($inputRights)){
                throw new Exception(_('Die Benutzerrechte weisen Fehler auf!'));
            }
        }


        // check password
        $pwd1len = strlen($inputPwd1);
        $pwd2len = strlen($inputPwd2);
        if($pwd1len > 0 && $pwd2len > 0) {
            if(strcmp($inputPwd1, $inputPwd2) !== 0){
                throw new Exception(_('Die Passwörter stimmen nicht überein!'));
            }

            if(!isSecurePassword($inputPwd1)){
                throw new Exception(_('Das Passwort muss aus mindestens 6 alphanumerischen Zeichen, 1 Großbuchstaben und 1 Kleinbuchstaben bestehen. Die Sonderzeichen !@#$_% sind erlaubt.'));
            }
        }

        // new user, without password
        if($inputId == 0){
            if($pwd1len == 0 && $pwd2len == 0){
                throw new Exception(_('Das Passwort wird benötigt!'));

            }
        }

        // one of two fields are filled
        if(($pwd1len > 0 && $pwd2len <= 0) || ($pwd1len <= 0 && $pwd2len > 0)){
            throw new Exception(_('Die Passwörter stimmen nicht überein!'));
        }



        $user = new User($inputId);

        // check if username exists
        if(strcmp($user->getUsername(), $inputUsername) !== 0){
            if(!is_username_available($inputUsername)){
                throw new Exception(_('Der Benutzername ist bereits vergeben!'));
            }
        }

        if($inputType == UserTypeEnum::ADMINISTRATOR){
            // set rights
            $rights = '';
            addUserRight($rights, $inputRights);
            $user->setRights($rights);
        }

        $user->setType($inputType);
        $user->setUsername($inputUsername);
        $user->setFirstname($inputFirstname);
        $user->setLastname($inputLastname);
        $user->setEmail($inputEmail);

        // password is only change able for unlocked users!
        if($user->getLocked() == 0){
            if($pwd1len > 0 && $pwd2len > 0){
                if(strcmp($inputPwd1, $inputPwd2) === 0){
                    $salt = getSalt(10);
                    $user->setPwd(hash(CFG_PASSWORD_HASH_ALGO, $inputPwd1 . $salt));
                    $user->setSalt($salt);
                }
            }
        }

        // unlocking
        // if new locking status is different to current locking status
        $generatedPwd = null;
        $sendLockEmail = false;
        if($user->getLocked() != $inputLocked){
            // prepare for sending an email
            $sendLockEmail = true;
            // set status
            $user->setLocked($inputLocked);

            // if this is an unlocking attempt
            if($inputLocked == 0){
                // generated new password
                $generatedPwd = getRandomPassword();
                // update user password
                $salt = getSalt();
                $user->setPwd(hash(CFG_PASSWORD_HASH_ALGO, $generatedPwd . $salt));
                $user->setSalt($salt);
            }
        }

        if(!$user->save()){
            throw new Exception(_('Das hat nicht geklappt!'));
        }

        // send email depending on locking status
        if($sendLockEmail){
            if($user->getLocked() == 0 && !is_empty($generatedPwd)){
                $body = renderTemplate('email/email_body_user_unlocked.twig', array('username' => $user->getUsername(), 'newpassword' => $generatedPwd), true);
                mailTo(array(
                    'subscriber' => $user->getEmail(),
                    'subject' => _('Ihr Konto wurde freigeschaltet!'),
                    'body' => nl2br($body),
                    'altbody' => strip_tags($body)
                ));
            } else if($user->getLocked() == 1){
                $body = renderTemplate('email/email_body_user_locked.twig', array('username' => $user->getUsername()), true);
                mailTo(array(
                    'subscriber' => $user->getEmail(),
                    'subject' => _('Ihr Konto wurde gesperrt!'),
                    'body' => nl2br($body),
                    'altbody' => strip_tags($body)
                ));
            }
        }

    } catch(Exception $e){
        flashRedirect( array('route' => $route, 'params' => array('id' => $inputId)), $e->getMessage());
    }

    flashRedirect('users', _('Benutzer gespeichert!'), FlashStatus::SUCCESS);
}

?>
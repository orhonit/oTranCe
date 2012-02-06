<?php
/**
 * This file is part of oTranCe http://www.oTranCe.de
 *
 * @package         oTranCe
 * @subpackage      Controllers
 * @version         SVN: $Rev$
 * @author          $Author$
 */
/**
 * Ajax Controller
 *
 * @package         oTranCe
 * @subpackage      Controllers
 */
class AjaxController extends Zend_Controller_Action
{
    /**
     * @var Msd_Config
     */
    protected $_config;

    /**
     * @var \Msd_Config_Dynamic
     */
    protected $_dynamicConfig;

    /**
     * User model
     * @var \Application_Model_User
     */
    protected $_userModel;

    /**
     * Languages model
     * @var \Application_Model_Languages
     */
    protected $_languagesModel;

    /**
     * Languages entries model
     * @var \Application_Model_LanguageEntries
     */
    protected $_entriesModel;

    /**
     * Array holding all languages
     * @var array
     */
    protected $_languages;

    /**
     * The fallback language
     * @var string
     */
    protected $_fallbackLanguage;

    /**
     * The fallback language data holding the values of the given keys
     * @var array
     */
    protected $_fallbackLanguageData;

    /**
     * Init
     *
     * @return void
     */
    public function init()
    {
        $this->_helper->layout()->disableLayout();

        $this->_config         = Msd_Registry::getConfig();
        $this->_dynamicConfig  = Msd_Registry::getDynamicConfig();
        $this->_languagesModel = new Application_Model_Languages();
        $this->_languages      = $this->_languagesModel->getAllLanguages();
        $this->_entriesModel   = new Application_Model_LanguageEntries();
        $this->_userModel      = new Application_Model_User();
    }

    /**
     * Translate an entry using Google translate
     *
     * @return void
     */
    public function translateAction()
    {
        $keyId            = $this->_request->getParam('key');
        $sourceLang       = $this->_request->getParam('source');
        $targetLang       = $this->_request->getParam('target');
        $entry            = $this->_entriesModel->getEntryById($keyId, array($sourceLang));
        $this->view->data = $this->_getTranslation(
            $entry[$sourceLang],
            $this->_languages[$sourceLang]['locale'],
            $this->_languages[$targetLang]['locale']
        );
    }

    /**
     * Import action.
     * Expects array of language entry keys as param in request and returns an array(key => status).
     * Status:
     *  0 = technical error
     *  1 = saved successfully
     *  2 = user has no edit right for this language
     *  3 = user is not allowed to add this new entry
     *
     * @return void
     */
    public function importKeyAction()
    {
        $ret = array();
        $params       = $this->_request->getParams();
        $languageId   = $params['languageId'];
        $fileTemplate = $params['fileTemplate'];
        $keys         = $params['keys'];
        $this->_data  = $this->_dynamicConfig->getParam('extractedData');
        $i = 0;
        $fallbackData = $this->_getFallbackLanguageData($keys, $fileTemplate, $languageId);
        $overallResult = true;
        foreach ($keys as $key) {
            $saveKey = true;
            if (!empty($fallbackData[$key]) && $fallbackData[$key] == $this->_data[$key]) {
                // value is the same as in the fallback language
                // check if user is allowed to import such phrases
                $saveKey = false;
                if ($this->_userModel->hasRight('importEqualVar')) {
                    $saveKey = true;
                }
            }

            if ($saveKey === false) {
                $ret[$i] = array('key' => $key, 'result' => 4);
            } else {
                $res = $this->_saveKey($key, $fileTemplate, $languageId);
                if ($res !== 1) {
                    $overallResult = false;
                }
                $ret[$i] = array('key' => $key, 'result' => $res);
            }
            $i++;
        }

        if ($overallResult === true) {
            //remove saved keys from session to speed up things
            //dont do it if an error occured because the user can click on "retry". We need the data in this case.
            foreach ($keys as $key) {
                unset($this->data[$key]);
            }
            $this->_dynamicConfig->setParam('extractedData', $this->_data);
        }

        $this->view->data = $ret;
    }

    /**
     * Triggers optimization of all database tables.
     * This is done after delete operations.
     *
     * @return void
     */
    public function optimizeTablesAction()
    {
        $results = $this->_languagesModel->optimizeAllTables();
        $ret     = array();
        foreach ($results as $res) {
            if (in_array($res['Msg_type'], array('status', 'info'))) {
                //ok
                $ret[$res['Table']] = 'ok';
            } else {
                //error - get info
                $ret[$res['Table']] = $res['Msg_text'];
            }
        }
        $this->view->data = $ret;
    }

    /**
     * Switch the language edit right of a user
     *
     * @return void
     */
    public function switchLanguageEditRightAction()
    {
        $languageId = (int) $this->_request->getParam('languageId', 0);
        $userId     = (int) $this->_request->getParam('userId', 0);
        if ($userId < 1 || $languageId < 1 || !$this->_userModel->hasRight('editUsers')) {
            //Missing param or no permission to change edit right
            $icon = $this->view->getIcon('Attention', $this->view->lang->L_ERROR, 16);
        } else {
            //revert actual right
            $languageEditRight = !$this->_userModel->hasLanguageEditRight($userId, $languageId);
            if ($languageEditRight == false) {
                //delete right
                $res = $this->_userModel->deleteUsersEditLanguageRight($userId, $languageId);
            } else {
                //add right
                $res = $this->_userModel->addUsersEditLanguageRight($userId, $languageId);
            }

            if ($res == true) {
                if ($languageEditRight == false) {
                    $icon = $this->view->getIcon('NotOk', $this->view->lang->L_CHANGE_STATUS, 16);
                } else {
                    $icon = $this->view->getIcon('Ok', $this->view->lang->L_CHANGE_STATUS, 16);
                }
            } else {
                $icon = $this->view->getIcon('Attention', $this->view->lang->L_ERROR_SAVING_LANGUAGE_EDIT_RIGHT, 16);
            }
        }

        $this->view->data = array('icon' => $icon);
        $this->render('json');
    }

    /**
     * Set/unset the right of a user
     *
     * @return void
     */
    public function switchRightAction()
    {
        $right  = (string) $this->_request->getParam('right', '');
        $userId = (int) $this->_request->getParam('userId', 0);
        $icon   = $this->view->getIcon('NotOk', $this->view->lang->L_NO, 16);
        if ($userId < 1 || $right == '' || !$this->_userModel->hasRight('editUsers')) {
            //Missing param or no permission to change edit right
            $data = array('error' => 'Invalid arguments', 'icon' => $icon);
        } else {
            //get actual right
            $userRights = $this->_userModel->getUserRights($userId);
            if ($userRights[$right] > 0) {
                //delete right
                $res = $this->_userModel->saveRight($userId, $right, 0);
            } else {
                //add right
                $res = $this->_userModel->saveRight($userId, $right, 1);
                if ($res == true) {
                    $icon = $this->view->getIcon('Ok', $this->view->lang->L_YES, 16);
                };
            }

            if ($res == true) {
                $data = array('error' => false, 'icon' => $icon);
            } else {
                //error saving
                $data = array('error' => $this->view->lang->L_ERROR_SAVING_RIGHT, 'icon' => $icon);
            }
        }

        $this->view->data = $data;
        $this->render('json');
    }

    /**
     * Activate/deactivate a language
     *
     * @return void
     */
    public function switchLanguageStatusAction()
    {
        $languageId = (int) $this->_request->getParam('languageId', 0);
        $icon       = $this->view->getIcon('Attention', $this->view->lang->L_ERROR, 16);
        if ($languageId < 1 || !$this->_userModel->hasRight('editLanguage')) {
            //Missing param or no permission to change status
            $data = array('icon' => $icon);
        } else {
            //get actual language
            $language = $this->_languagesModel->getLanguageById($languageId);
            //switch status
            $language['active'] = ($language['active'] > 0) ? 0 : 1;
            $res = $this->_languagesModel->saveLanguageStatus($languageId, $language['active']);
            if ($res === true) {
                if ($language['active'] > 0) {
                    $icon = $this->view->getIcon('Ok', $this->view->lang->L_CHANGE_STATUS, 16);
                } else {
                    $icon = $this->view->getIcon('NotOk', $this->view->lang->L_CHANGE_STATUS, 16);
                }
                $data = array('icon' => $icon);
            } else {
                $data = array('icon' => $icon);
            }
        }

        $this->view->data = $data;
        $this->render('json');
    }

    /**
     * Save a key and it's value to the database.
     *
     * @param string $key          Keyname to save
     * @param int    $fileTemplate Id of the file template
     * @param int    $languageId   Id of language
     *
     * @return int
     */
    private function _saveKey($key, $fileTemplate, $languageId)
    {
        // check edit right for language
        $userEditRights = $this->_userModel->getUserLanguageRights();
        if (!in_array($languageId, $userEditRights)) {
            //user is not allowed to edit this language
            return 2;
        }

        if (!$this->_entriesModel->hasEntryWithKey($key, $fileTemplate)) {
            //it is a new entry - check rights
            if (!$this->_userModel->hasRight('addVar')) {
                return 3;
            } else {
                // user is allowed to add new keys -> create it
                $this->_entriesModel->saveNewKey($key, $fileTemplate);
            }
        }

        // ok - we can save the value -> key id
        $entry = $this->_entriesModel->getEntryByKey($key, $fileTemplate);
        $keyId = $entry['id'];
        $value = $this->_data[$key];
        $res = $this->_entriesModel->saveEntries($keyId, array($languageId => $value));
        if ($res === true) {
            return 1;
        } else {
            return 0;
        }
    }


    /**
     * Get the translations of the keys for the fallbackLanguage and save them to aprivate property
     *
     * @param array $keys       The language keys
     * @param int   $templateId Id of the file template
     * @param int   $languageId Id of the language
     *
     * @return array|false
     */
    private function _getFallbackLanguageData($keys, $templateId, $languageId)
    {
        $fallbackLanguageId = $this->_languagesModel->getFallbackLanguage();
        if ($fallbackLanguageId == $languageId) {
            // imported language is the fallback language - nothing to check
            return false;
        }
        return $this->_entriesModel->getEntriesByKeys($keys, $templateId, $fallbackLanguageId);
    }

    /**
     * Get a Google translation
     *
     * @param  string $text
     * @param  string $sourceLang
     * @param  string $targetLang
     *
     * @return string
     */
    private function _getTranslation($text, $sourceLang, $targetLang)
    {
        if ($text == '') {
            return '';
        }
        $sourceLang = $this->_mapLangCode($sourceLang);
        $targetLang = $this->_mapLangCode($targetLang);
        $config = Msd_Registry::getConfig();
        $googleConfig = $config->getParam('google');
        $pattern = 'https://www.googleapis.com/language/translate/v2?key=%s'
                   .'&q=%s&source=%s&target=%s' ;
        $url = sprintf($pattern, $googleConfig['apikey'], urlencode($text), $sourceLang, $targetLang);
        $handle = @fopen($url, "r");
        if ($handle) {
            $contents = fread($handle, 4*4096);
            fclose($handle);
        } else {
            return 'Error: not possible!';
        }
        $response = json_decode($contents);
        $data = $response->data->translations[0]->translatedText;
        return $data;
    }

    /**
     * Convert lang code like vi_VN into Googles code vn
     *
     * @param  string $code
     *
     * @return string
     */
    private function _mapLangCode($code)
    {
        $pos = strrpos($code, '_');
        if ($pos === false) {
            return $code;
        }
        return substr($code, 0, $pos);
    }
}

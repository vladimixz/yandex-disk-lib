<?php

class YandexDisc extends CApplicationComponent
{
    
    public $pass_file = '';
    public $yandexId;
    public $yandexKey;
    public $yandex_disc_file = 'ucat_yandex_disc.txt';
    public $renamed_path = 'disk:/Фото-переименованные';


    /**
     * Get token from file or API and save it
     * @return type
     */
    public function getToken()
    {
        $this->pass_file = dirname(Yii::app()->basePath) . "/" . Yii::app()->params['tempRelPath'] . $this->yandex_disc_file;
        $last_query = array('last_query' => Yii::app()->request->getPost('path'));
        if (is_file($this->pass_file) && empty(Yii::app()->getRequest()->getQuery('code'))) {
            $token = $this->verifyTmpToken($last_query);
        } elseif (is_file($this->pass_file) && !empty(Yii::app()->getRequest()->getQuery('code'))) {
            $file_content = json_decode(file_get_contents($this->pass_file));
            if (isset($file_content->token)) {
                $token = $this->verifyTmpToken($last_query);
            } else {
                $token_data = $this->tokenRequest();
                $token_data["last_query"] = isset($file_content->last_query) ? $file_content->last_query : null;
                $this->createTmpFile(json_encode($token_data));
                $token = $token_data['token'];
            }
        } elseif (!is_file($this->pass_file) && empty(Yii::app()->getRequest()->getQuery('code'))) {
            $this->oauthQuery(json_encode($last_query));
        }
        return $token;
    }
    
    /**
     * Request for getting token
     * @return type
     */
    public function tokenRequest()
    {
        $url = "https://oauth.yandex.ru/token";
        $post_data = array (
            'grant_type' => "authorization_code",
            'code' => filter_input(INPUT_GET, "code"),
            'client_id' => $this->yandexId,
            'client_secret' => $this->yandexKey,
        );
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        $output = json_decode(curl_exec($ch));
        curl_close($ch);
        if (!array_key_exists("error", $output)) {
            return array('token' => $output->access_token, 'exparied_date' => date("Y-m-d H:i:s", strtotime("+" . $output->expires_in . " seconds")));
        } else {
            Yii::app()->request->redirect("https://oauth.yandex.ru/authorize?response_type=code&client_id=" . $this->yandexId, true);
            die;
        }
    }
    
    /**
     * Varify data in tmp file
     * @param type $last_query
     * @return type
     */
    public function verifyTmpToken($last_query)
    {
        $data = json_decode(file_get_contents($this->pass_file));
        if (empty($data) || count((array) $data) < 2 || empty(preg_grep("/^\d{4}+-+\d{2}+-+\d{2}+\s+\d{2}+:\d{2}+:\d{2}$/", [$data->exparied_date]))) {
            $this->oauthQuery(json_encode($last_query));
        }
        if (date("Y-m-d H:i:s") > $data->exparied_date) {
            $this->oauthQuery(json_encode($last_query));
        } else {
            return $data->token;
        }
    }
    
    /**
     * Create tmp file for saving token
     * @param type $content
     */
    public function createTmpFile($content)
    {
        $file = fopen($this->pass_file, "w");
        fwrite($file, $content);
        fclose($file);
    }
    
    public function getRenamedFolderList()
    {
        $files_object = $this->getFilesList($this->renamed_path);
        return $files_object->_embedded->items;
    }

    /**
     * Get files by path
     * @param type $path
     * @return type
     */
    public function getFilesList($path)
    {
        set_time_limit(0);
        $token = $this->getToken();
        $info = curl_init();
        curl_setopt($info, CURLOPT_URL, 'https://cloud-api.yandex.net:443/v1/disk/resources?limit=10000&path=' . $path);
        curl_setopt($info, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($info, CURLOPT_HTTPHEADER, ["Accept: */* \nAuthorization: OAuth " . $token]);
        $output = json_decode(curl_exec($info));
        if (isset($output->error)) {
            if ($output->error == 'UnauthorizedError' || $output->error == 'DiskNotFoundError') {
                $this->oauthQuery(json_encode(['last_query' => $path]));
            }
        }
        curl_close($info);
        return $output;
    }
    
    /**
     * Get files with invalid gtin
     * @param type $path
     * @return type
     */
    public function getInvalidGtin($path)
    {
        $invalid_gtins = [];
        $files_list = $this->getFilesList($path);
        if (isset($files_list->error)) {
            $invalid_gtins[] = $files_list->message;
        } else {
            foreach ($files_list->_embedded->items as $file) {
                $name = substr($file->name, 0, strrpos($file->name, "."));
                if (preg_match('/^(case_)?\d{8,14}+_(p|\d{1,2})$/', $name)) {
                    preg_match('/\d{8,14}/', $name, $GTIN);
                    if (!Product::validationGTIN($GTIN[0])) {
                        $invalid_gtins[] = $file->name;
                    }
                } elseif (!preg_match('/_(p|\d{1,2})$/', $name)) {
                    $invalid_gtins[] = $file->name;
                }
            }
        }
        $this->unsetLastQuery();
        return $invalid_gtins;
    }
    
    /**
     * Delete query from tmp file
     */
    public function unsetLastQuery()
    {
        if (is_file($this->pass_file)) {
            $file_content = json_decode(file_get_contents($this->pass_file));
            if (isset($file_content->last_query)) {
                unset($file_content->last_query);
                $this->createTmpFile(json_encode($file_content));
            }
        }
    }
    
    /**
     * Query for oauth code
     * @param type $last_query
     */
    public function oauthQuery($last_query)
    {
        $this->createTmpFile($last_query);
        Yii::app()->request->redirect("https://oauth.yandex.ru/authorize?response_type=code&client_id=" . $this->yandexId, true);
        die;
    }
}

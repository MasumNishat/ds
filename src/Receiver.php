<?php
namespace Server;

final class Receiver {
    private static ?Receiver $instance = null;
    private array $config = [];
    /**
     * gets the instance via lazy initialization (created on first usage)
     */
    public static function getInstance(array $config): ?Receiver
    {
        if (Receiver::$instance === null) {
            Receiver::$instance = new Receiver($config);
        }
        return Receiver::$instance;
    }

    private function __construct (array $config) {
        $this->config = $config;
    }

    /**
     * @param string $file
     * @param bool $create
     * @param string $type
     * @return mixed
     */
    public function read(string $file, bool $create = true, string $type = 'string'): mixed
    {
        $return = '';
        if (file_exists($file)) {
            if ($type == 'string') {
                $return = file_get_contents($file)?: '';
            } elseif ($type == 'array') {
                $return  = json_decode(file_get_contents($file) , true);
                $return = $return == null? []:$return;
            } elseif ($type == 'object') {
                $return  = json_decode(file_get_contents($file));
                $return = $return == null? new stdClass() : $return;
            } elseif ($type == 'int') {
                $return  = file_get_contents($file)+0;
            } else {
                $return = file_get_contents($file);
            }
        } elseif ($create) {
            $data = $type == 'int'? '0' : ($type == 'array' || $type == 'object'? '[]': '');
            $this->write($file, $data, 'w');
        }
        return $return;
    }
    public function write (string $file, string $data, string $mode = 'a') {
        file_put_contents($file, $data, $mode == 'a'? FILE_APPEND: 0);
//        $f = fopen($file, $mode);
//        flock($f, LOCK_EX);
//        fwrite($f, $data);
//        flock($f, LOCK_UN);
//        fclose($f);
    }
    public function getSerial(string $file): int
    {
        $data = $this->read($file, false, 'int');
        if (empty($data) || $data == '') {
            $data = 0;
        }
        return $data;
    }

    public function getData() {
        @mkdir($this->config['attachment-folder']);
        @mkdir($this->config['smtp-add']);
        $webMailFolderSerial = ($this->getSerial($this->config['webMailFolderSerialFile']) % 10)+1;
        @mkdir($this->config['webMailFolderPrefix'].$webMailFolderSerial);
        //initialising
        $servers = include $this->config['servers'];
        $key = false;
        $resp = [];
        $smtp = $this->read($this->config['smtp-json'], false, 'array');
        $serial = $this->read($this->config['server_serial']) % count($servers);

        //attachment grabbing
        $whc = new webhookclient();
        $whc->listen_headers();
        $whc->listen_value();
        $whc->listen_file($this->config['fileAttachKey']);
        $data = json_decode($whc->data['content'], true);
        if (count($whc->file) > 0) {
            $data['attachment'] = $whc->file;
            foreach ($data['attachment'] as $arr) {
                @move_uploaded_file($arr["tmp_name"], $this->config['fileAttachKey']. "/" . $arr["name"]);
            }
        }

        if (isset($data['data']['webmail-mail']) && trim($data['data']['webmail-mail']) != '') {
            $smtpKey = array_search($data['data']['webmail-mail'], array_column($smtp, 'mail'));
            if ($smtpKey ===false) {
                $data['data']['webmail-mail'] = preg_replace('/[^a-z0-9]+/', '_', str_replace(" ", "", strtolower($data['model']))) . sprintf('%03u', $serial) . '@' . $servers[$serial]['domain'];
            }
            $domain = explode('@', $data['data']['webmail-mail'])[1];
            $data['last-id'] = md5(uniqid('', true)) . '@' . $domain;
            $key = array_search($domain, array_column($servers, 'domain'));

            if ($key === false && $smtpKey !== false) {
                unset($smtp[$smtpKey]);
                sort($smtp);
                $this->write('smtp.json', json_encode($smtp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            }
        }

        if ($key === false) {
            $serialData = $serial + 1;
            $this->write($this->config['server_serial'], "$serialData");
            $data['data']['webmail-mail'] = preg_replace('/[^a-z0-9]+/', '_', str_replace(" ", "", strtolower($data['model']))) . sprintf('%03u', $serial) . '@' . $servers[$serial]['domain'];
            $new['mail'] = $data['data']['webmail-mail'];
            $new['pass'] = $data['pass'];
            $new['admin'] = $servers[$serial]['mail'];
            $new['admin-pass'] = $servers[$serial]['pass'];
            $data['last-id'] = md5(uniqid('', true)) . '@' . $servers[$serial]['domain'];
            $smtpKey = array_search($data['data']['webmail-mail'], array_column($smtp, 'mail'));
            if ($smtpKey !== false) {
                $new = [];
            }
            if (!empty($new)) {
                $this->write($this->config['smtp-add']."/".uniqid(), json_encode($new));
            }
        } else {
            $host = explode('@', $servers[$key]['mail'])[1];
            $new['mail'] = $data['data']['webmail-mail'];
            $new['pass'] = $data['pass'];
            $new['admin'] = $servers[$serial]['mail'];
            $new['admin-pass'] = $servers[$serial]['pass'];
            $new['imap-host'] = $host;
            $new['imap-port'] = "993";
            $new['smtp-host'] = $host;
            $new['smtp-port'] = "465";
            $new['smtp-enc'] = "ssl";
            if ($smtpKey === false) {
                $this->write($this->config['smtp-add']."/".uniqid(), json_encode($new));
                // smtp entry responsibility goes to add.php script
                unset($new['admin'], $new['admin-pass']);
            }
        }

        $this->write('webmail_serial', "$webMailFolderSerial");

        file_put_contents($this->config['webMailFolderPrefix'].$webMailFolderSerial."/" . $data['data']['address'] . uniqid(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $resp['webmail-mail'] = $data['data']['webmail-mail'];
        $resp['last-id'] = $data['last-id'];
        print_r(json_encode($resp));
    }
}

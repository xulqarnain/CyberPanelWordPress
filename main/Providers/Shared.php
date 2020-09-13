<?php

require_once(CPWP_PLUGIN_DIR . 'main/CommonUtils.php');
require_once(CPWP_PLUGIN_DIR . 'main/WPCPHTTP.php');

class SharedCP extends WPCPHTTP
{

    function __construct($job, $data, $order_id = null)
    {
        $this->job = $job;
        $this->data = $data;
        $this->orderid = $order_id;
    }

    function fetchPlans()
    {
        $wpcp_provider = sanitize_text_field($this->data['wpcp_provider']);

        global $wpdb;
        $result = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}cyberpanel_providers WHERE name = '$wpcp_provider'");

        $token = json_decode($result->apidetails)->token;

        $localIP = explode(';', $token)[0];
        $localUser = explode(';', $token)[1];
        $token = sprintf('Basic %s==', explode(';', $token)[2]);

        $this->body = array(
            'serverUserName' => $localUser,
            'serverPassword' => $token,
            'controller' => 'fetchPackages'
        );

        $this->url = sprintf('https://%s/cloudAPI', $localIP);

        $response = $this->HTTPPostCall($token);
        $data = json_decode(wp_remote_retrieve_body($response));

        $packages = json_decode($data->data);

        $finalResult = '';

        foreach ($packages as $package) {
            $finalResult = $finalResult . sprintf('<option>%s</option>', $package->packageName . ',' . $package->allowedDomains);
        }

        $data = array(
            'status' => 1,
            'result' => $finalResult
        );

        return $data;
    }

    function createServer()
    {
        ## Fetch token image and provider that can be used to create this server. This function will populate $this->globalData

        $this->fetchImageTokenProvider();

        ##

        $this->globalData['serverName'] = substr(str_shuffle(WPCPHTTP::$permitted_chars), 0, 10);
        $this->globalData['CyberPanelPassword'] = substr(str_shuffle(WPCPHTTP::$permitted_chars), 0, 15);
        $this->globalData['RootPassword'] = substr(str_shuffle(WPCPHTTP::$permitted_chars), 0, 15);

        $this->body = array(
            'serverUserName' => $this->globalData['serverUser'],
            'serverPassword' => $this->globalData['serverPassword'],
            'controller' => 'submitWebsiteCreation',
            'ssl' => 0,
            'dkimCheck' => 1,
            'openBasedir' => 0,
            'websiteOwner' => $this->globalData['CPUserName'],
            'phpSelection' => 'PHP 7.2',
            'adminEmail' => $this->globalData['email'],
            'package' => $this->globalData['finalPlan'],
            'UserPassword' => $this->globalData['CyberPanelPassword'],
            'UserAccountName' => $this->globalData['CPUserName'],
            'FullName' => $this->globalData['CPUserName'],
            'domainName' => $this->globalData['finalDomain']
        );

        $response = $this->HTTPPostCall($this->globalData['serverPassword']);
        CommonUtils::writeLogs(wp_remote_retrieve_body($response),CPWP_ERROR_LOGS);

        try{

            CommonUtils::writeLogs('Setup credentials.', CPWP_ERROR_LOGS);

//            $this->globalData['serverID'] = $respData->server->id;
//            $this->globalData['ipv4'] = $respData->server->public_net->ipv4->ip;
//            $this->globalData['ipv6'] = $respData->server->public_net->ipv6->ip;
//            $this->globalData['cores'] = $respData->server->server_type->cores;
//            $this->globalData['memory'] = $respData->server->server_type->memory . 'G';
//            $this->globalData['disk'] = $respData->server->server_type->disk . 'GB NVME';
//            $this->globalData['datacenter'] = $respData->server->datacenter->name;
//            $this->globalData['city'] = $respData->server->datacenter->location->city;

//            if( ! isset($this->globalData['serverID']) ){
//                throw new Exception($respData->error->message);
//            }
        }
        catch (Exception $e) {
  //          CommonUtils::writeLogs(sprintf('Failed to create server for product id: %s, order id was %s and product name %s. Error message: %s.', $this->globalData['productID'], $this->orderid, $this->globalData['productName'], $respData->error->message), CPWP_ERROR_LOGS);
            return 0;
        }

        //$this->serverPostProcessing();

        return 1;
    }

    function cancelNow()
    {

        $serverID = sanitize_text_field($this->data['serverID']);
        $this->url = 'https://api.hetzner.cloud/v1/servers/' . $serverID;
        CommonUtils::writeLogs('Setup credentials.', CPWP_ERROR_LOGS);
        $this->setupTokenImagePostID();
        CommonUtils::writeLogs('Credentials set.', CPWP_ERROR_LOGS);
        $response = $this->HTTPPostCall($this->token, 'DELETE');
        $respData = json_decode(wp_remote_retrieve_body($response));

        try{
            $status = $respData->action->status;

            if( ! isset($status) ){
                throw new Exception($respData->error->message);
            }

            $this->globalData['json'] =  $this->data['json'];

            $this->serverPostCancellation();
        }
        catch (Exception $e) {
            CommonUtils::writeLogs(sprintf('Failed to cancel server. Error message: %s', $e->getMessage()), CPWP_ERROR_LOGS);
            $data = array(
                'status' => 0
            );
            if( !wp_doing_cron() && $this->data['json'] == 1) {
                wp_send_json($data);
            }
        }
    }

    function shutDown()
    {

        $serverID = sanitize_text_field($this->data['serverID']);

        $this->url = sprintf('https://api.hetzner.cloud/v1/servers/%s/actions/poweroff', $serverID);

        $this->setupTokenImagePostID();

        $response = $this->HTTPPostCall($this->token, null, 0);
        $respData = json_decode(wp_remote_retrieve_body($response));

        try{
            $status = $respData->action->status;

            if( ! isset($status) ){
                throw new Exception($respData->error->message);
            }
            $data = array(
                'status' => 1,
            );

            if(! wp_doing_cron()) {
                wp_send_json($data);
            }
        }
        catch (Exception $e) {
            CommonUtils::writeLogs(sprintf('Failed to shutdown server. Error message: %s', $e->getMessage()), CPWP_ERROR_LOGS);
            $data = array(
                'status' => 0
            );
            if(! wp_doing_cron()) {
                wp_send_json($data);
            }
        }
    }

    function rebuildNow()
    {

        $this->setupTokenImagePostID();

        $this->body = array(
            'image' => $this->image,
        );

        $serverID = sanitize_text_field($this->data['serverID']);

        $this->url = sprintf('https://api.hetzner.cloud/v1/servers/%s/actions/rebuild', $serverID);
        $response = $this->HTTPPostCall($this->token);
        $respData = json_decode(wp_remote_retrieve_body($response));

        try{
            $status = $respData->action->status;
            if( ! isset($status) ){
                throw new Exception($respData->error->message);
            }
            $data = array(
                'status' => 1,
            );
            wp_send_json($data);
        }
        catch (Exception $e) {
            CommonUtils::writeLogs(sprintf('Failed to rebuild server. Error message: %s', $e->getMessage()), CPWP_ERROR_LOGS);
            $data = array(
                'status' => 0
            );
            wp_send_json($data);
        }
    }

    function serverActions()
    {
        $this->setupTokenImagePostID();
        $serverID = sanitize_text_field($this->data['serverID']);
        $this->url = sprintf('https://api.hetzner.cloud/v1/servers/%s/actions', $serverID);
        $response = $this->HTTPPostCall($this->token, 'GET');
        $respData = json_decode(wp_remote_retrieve_body($response));

        try{
            $this->globalData['actions'] = $respData->actions;

            if( ! isset($this->globalData['actions']) ){
                throw new Exception($respData->error->message);
            }

            $this->serverPostActions();
        }
        catch (Exception $e) {
            CommonUtils::writeLogs(sprintf('Failed to retrieve server actions. Error message: %s', $e->getMessage()),CPWP_ERROR_LOGS);
            $data = array(
                'status' => 0
            );
            wp_send_json($data);
        }
    }

    function rebootNow()
    {
        $this->setupTokenImagePostID();

        $serverID = sanitize_text_field($this->data['serverID']);

        $this->url = sprintf('https://api.hetzner.cloud/v1/servers/%s/actions/reset', $serverID);
        $response = $this->HTTPPostCall($this->token, null, 0);
        $respData = json_decode(wp_remote_retrieve_body($response));

        try{
            $status = $respData->action->status;
            if( ! isset($status) ){
                throw new Exception($respData->error->message);
            }
            $data = array(
                'status' => 1,
            );

            CommonUtils::writeLogs(sprintf('Value of json %d.', $this->data['json']), CPWP_ERROR_LOGS);

            if( !wp_doing_cron() && $this->data['json'] == 1) {
                wp_send_json($data);
            }
        }
        catch (Exception $e) {
            CommonUtils::writeLogs(sprintf('Failed to reboot server. Error message: %s', $e->getMessage()), CPWP_ERROR_LOGS);
            $data = array(
                'status' => 0
            );
            if( !wp_doing_cron() && $this->data['json'] == 1) {
                wp_send_json($data);
            }
        }
    }
}
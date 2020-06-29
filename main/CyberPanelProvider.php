<?php

require_once(CPWP_PLUGIN_DIR . 'main/CommonUtils.php');

class CyberPanelProvider extends WPCPHTTP
{
    function __construct($job, $data)
    {
        $this->job = $job;
        $this->data = $data;
    }
    function connectProvider(){


        $provider = sanitize_text_field($this->data['provider']);
        $name = sanitize_text_field($this->data['name']);
        $token = sanitize_text_field($this->data['token']);

        $token = 'Bearer ' . $token;

        $finalDetails = json_encode(array('token'=> $token));

        /// Check if hostname alrady exists
        global $wpdb;

        $result = $wpdb->get_row( "SELECT name FROM {$wpdb->prefix}cyberpanel_providers WHERE name = '$name'" );

        if ($result == null) {
            $wpdb->insert(
                $wpdb->prefix . TN_CYBERPANEL_PVD,
                array(
                    'provider' => $provider,
                    'name' => $name,
                    'apidetails' => $finalDetails
                ),
                array(
                    '%s',
                    '%s',
                    '%s'
                )
            );


            $this->job->setDescription(sprintf('Successfully configured %s account named: %s', $provider, $name));
            $this->job->updateJobStatus(WPCP_JobSuccess, 100);

            $cu = new CommonUtils(1, '');
            $cu->fetchJson();
        }
        else{

            $this->job->setDescription(sprintf('Failed to configure %s account named: %s. Error message: Account already exists.', $provider, $name));
            $this->job->updateJobStatus(WPCP_JobFailed, 0);

            $cu = new CommonUtils(0, 'Already exists.');
            $cu->fetchJson();
        }
    }
}
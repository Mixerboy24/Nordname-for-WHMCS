<?php

use WHMCS\View\Menu\Item as MenuItem;

if(!defined('WHMCS'))
    die('This file cannot be accessed directly');


require_once __DIR__ . '/nordname.php';

add_hook('ClientAreaHeadOutput', 50, function($vars) {
    if($vars['templatefile'] !== 'configuredomains')
        return;
        
    $settings = nordname_get_module_settings();
        
    $domains = [];
    // First find domains in cart that have NordName set as registrar.
    foreach ($_SESSION['cart']['domains'] as $key => $domain) {
        $split = explode(".", $domain["domain"], 2);
        if (count($split) == 2) {
            $tld = $split[1];
            if (nordname_get_registrar_for_tld($tld) == "nordname") {
                $domains[] = array("domain" => $domain["domain"], "tld" => $tld, "key" => $key);
            }
        }
    }
    
    ob_start();
    ?>
    <script type="text/javascript">
    $(document).ready(function() {
    <?php
    
    // Then, for these domains, check if the TLDs have country restrictions and show warning text.
    if ($settings["display_restrictions"]) {
        foreach ($domains as $obj) {
            $tld = nordname_get_tld_data($obj["tld"], $settings);
            if (!empty($tld["legal"]["registrant_countries"])) {
                ?>
                $("#frmConfigureDomains .sub-heading").eq(<?= $obj["key"] ?>).after("<p>If you do not fulfill this requirement, there may be a trustee service available. Contact us.</p>");
                $("#frmConfigureDomains .sub-heading").eq(<?= $obj["key"] ?>).after("<p><?=\Lang::trans('Registrations under this Top Level Domain require you to be a resident in one of the following countries: ') . implode(",", $tld["legal"]["registrant_countries"])?></p>");

                <?php
            }
        }
    }
    
    ?>
    });
    </script>
    <?php
    return ob_get_clean();
});

/**
 * Certain NordName transfers execute immediately.
 *
 * This will immediately check tranfer status after it has been submitted, and if applicable,
 * update domain status to Active
 */
add_hook('AfterRegistrarTransfer', 50, function($vars) {
    if(@$vars['params']['registrar'] !== 'nordname') {
        return;
    }

    /** @noinspection UnusedFunctionResultInspection */
    RegCallFunction($vars['params'], 'ImmediateTransferCheck');
});

// Disable Registrar Lock warning in Client Area if TLD does not support it.
add_hook( 'ClientAreaPageDomainDetails', 1, function( array $vars ) {
    $current = Menu::context( 'domain' );
    $domain = $vars["domain"];
    $tld = substr( $domain, strrpos( $domain, "." ) + 1 );

    $tld_data = nordname_get_tld_data($tld);
    $lock_supported = $tld_data["features"]["supports_lock"];

    if (!$lock_supported) {
        $vars['managementoptions']['locking'] = false;
        $vars['lockstatus'] = false;

        return $vars;
    }
});

// Disable Registrar Lock item in Client Area sidebar if TLD does not support it.
add_hook( 'ClientAreaPrimarySidebar', 1, function( MenuItem $primarySidebar ) {
    $current = Menu::context( 'domain' );
    $domain = $current->domain;
    $tld = substr( $domain, strrpos( $domain, "." ) + 1 );
    if ( ! is_null( $primarySidebar->getChild( 'Domain Details Management' ) ) ) {
        $tld_data = nordname_get_tld_data($tld);
        $lock_supported = $tld_data["features"]["supports_lock"];
        if ( !$lock_supported ) {
            $primarySidebar->getChild( 'Domain Details Management' )->removeChild( 'Registrar Lock Status' );
        }

        // Do not show DNS management option if service is disabled.
        if ( !$current["dnsmanagement"] && !is_null($primarySidebar->getChild( 'Domain Details Management' )->getChild( \Lang::trans('tabDomainDNS') ))) {
            $primarySidebar->getChild( 'Domain Details Management' )->removeChild( \Lang::trans('tabDomainDNS'));
        }
    }
});

add_hook('AdminAreaPage', 1, function ($vars) {
    nordname_checkGroupOrAdd();
    nordname_checkApiOrAdd();
});

function nordname_checkGroupOrAdd() {
    $apiCatalogGroups = \WHMCS\Api\V1\Catalog::get()->getGroups();
    if (!array_key_exists('NordNameRegistrar', $apiCatalogGroups))
        \WHMCS\Api\V1\Catalog::add([], ['NordNameRegistrar' => array('name' => 'NordName Registrar')]);
}

function nordname_checkApiOrAdd() {
    $apiCatalogActions = \WHMCS\Api\V1\Catalog::get()->getActions();

    if (!array_key_exists('nordnamegettld', $apiCatalogActions)) {
        \WHMCS\Api\V1\Catalog::add(array('nordnamegettld' => array(
            'group' => 'NordNameRegistrar',
            'name' => 'NordNameGetTld',
            'default' => 0
        )));
    }

    if (!array_key_exists('nordnamedomaincheck', $apiCatalogActions)) {
        \WHMCS\Api\V1\Catalog::add(array('nordnamedomaincheck' => array(
            'group' => 'NordNameRegistrar',
            'name' => 'NordNameDomainCheck',
            'default' => 0
        )));
    }
}

add_hook('AcceptOrder', 1, function($vars) {
    $settings = nordname_get_module_settings();
    if ($settings["override_registrar"] == "on")
        update_query('tbldomains', array("registrar" => "nordname"), ['orderid' => $vars['orderid']]);
});

// mb24-edit: Translate additional domain fields on the fly (reads from $_LANG overrides)
add_hook('ClientAreaPageCart', 1, function($vars) {
    global $_LANG; // mb24-edit: Import WHMCS language variables
    
    if ($vars['templatefile'] == 'configuredomains') {
        $domains = $vars['domains'];
        if (is_array($domains)) {
            
            // mb24-edit: Define description texts to translate
            $descriptionOptions = [
                'Legal type of registrant',
                'EURid Geographical Restrictions. In order to register a .EU domain name, you must meet certain eligibility requirements.'
            ];

            // mb24-edit: Define dropdown options to translate
            $dropdownOptions = [
                // .IT
                'Italian and foreign natural persons',
                'Companies/one man companies',
                'Freelance workers/professionals',
                'non-profit organizations',
                'public organizations',
                'other subjects',
                'non natural foreigners',
                // .EU
                'Company - Undertakings having their registered office or central administration and/or principal place of business within the European Community',
                'Individual - Natural persons resident within the European Community',
                'Organization - Organizations established within the European Community without prejudice to the application of national law'
            ];
            
            foreach ($domains as $k => $domain) {
                if (isset($domain['fields']) && is_array($domain['fields'])) {
                    $newFields = [];
                    foreach ($domain['fields'] as $key => $fieldHTML) {
                        
                        // mb24-edit: Translate descriptions dynamically
                        foreach ($descriptionOptions as $enDesc) {
                            if (isset($_LANG[$enDesc])) {
                                $fieldHTML = str_replace($enDesc, $_LANG[$enDesc], $fieldHTML);
                            }
                        }
                        
                        // mb24-edit: Translate dropdown options dynamically
                        foreach ($dropdownOptions as $enText) {
                            if (isset($_LANG[$enText])) {
                                $fieldHTML = str_replace('>' . $enText . '<', '>' . $_LANG[$enText] . '<', $fieldHTML);
                            }
                        }
                        
                        $newFields[$key] = $fieldHTML;
                    }
                    $domains[$k]['fields'] = $newFields;
                }
            }
            return ['domains' => $domains];
        }
    }
});

?>
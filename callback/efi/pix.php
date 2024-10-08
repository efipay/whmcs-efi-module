<?php

require_once __DIR__ . '/../../../../init.php';


include_once __DIR__ . '../../efi/gerencianet_lib/Gerencianet_WHMCS_Interface.php';
include_once __DIR__ . '../../efi/gerencianet_lib/database_interaction.php';

use WHMCS\Config\Setting;


App::load_function('gateway');

App::load_function('invoice');


function validateWebhookPix($gatewayParams, $hmac): bool
{
    $systemUrl   = Setting::getValue('SystemURL');

    $url = $systemUrl . '/modules/gateways/callback/efi/pix.php';
    $hmacStorage = hash_hmac('sha256', $url, $gatewayParams['clientIdProd']);
    return ($hmacStorage == $hmac);
}



// Fetch gateway configuration parameters

$gatewayModuleName = 'efi';

$gatewayParams = getGatewayVariables($gatewayModuleName);
$mtls = ($gatewayParams['mtls'] != 'on');

if ($mtls) {
    $hmac = $_GET['hmac'] ?? null;
    
    if (validateWebhookPix($gatewayParams, $hmac)) {
        // Hook data retrieving

        @ob_clean();

        $postData = json_decode(file_get_contents('php://input'));



        // Hook validation

        if (isset($postData->evento) && isset($postData->data_criacao)) {

            header('HTTP/1.0 200 OK');



            exit();
        }



        $pixPaymentData = $postData->pix;



        // Hook manipulation

        if (empty($pixPaymentData)) {

            showException('Exception', array('Pagamento Pix não recebido pelo Webhook.'));
        } else {

            header('HTTP/1.0 200 OK');

            $tableName = 'tblgerencianetpix';

            $txID  = $pixPaymentData[0]->txid;

            $e2eID = $pixPaymentData[0]->endToEndId;



            $success = !empty($e2eID);



            // Retrieving Invoice ID from 'tblgerencianetpix'

            $savedInvoice = find($tableName, 'txid', $txID);



            // Checking if the invoice has already been paid

            if (empty($savedInvoice['e2eid'])) {

                // Validate Callback Invoice ID

                $invoiceID = checkCbInvoiceID($savedInvoice['invoiceid'], $gatewayParams['name']);



                $conditions = [

                    'invoiceid' => $invoiceID,

                ];

                $dataToUpdate = [

                    'e2eid' => $e2eID,

                ];



                // Saving e2eid in table 'tblgerencianetpix'

                update($tableName, $conditions, $dataToUpdate);

                $pixDiscount = str_replace('%', '', $gatewayParams['pixDiscount']);



                if ($success) {

                    $paymentFee = '0.00';

                    $paymentAmount = $pixPaymentData[0]->valor;

                    if ($pixDiscount > 0) {

                        extra_amounts_Gerencianet_WHMCS($savedInvoice['invoiceid'], $pixDiscount, 1);
                    }





                    addInvoicePayment($invoiceID, $txID, $paymentAmount, $paymentFee, $gatewayModuleName);
                } else {

                    showException('Exception', array("Pagamento Pix não efetuado para a Fatura #$invoiceID"));
                }
            }
        }
    }
}

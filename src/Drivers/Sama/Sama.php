<?php

namespace Shetabit\Multipay\Drivers\Sama;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Sama extends Driver
{
    /**
     * Sama Client.
     *
     * @var object
     */
    protected $client;

    /**
     * Invoice
     *
     * @var Invoice
     */
    protected $invoice;

    /**
     * Driver settings
     *
     * @var object
     */
    protected $settings;

    /**
     * Sama constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object)$settings;
        $this->client = new Client();
    }

    /**
     * Retrieve data from details using its name.
     *
     * @return string
     */
    private function extractDetails($name)
    {
        return empty($this->invoice->getDetails()[$name]) ? null : $this->invoice->getDetails()[$name];
    }

    /**
     * Purchase Invoice.
     *
     * @return string
     *
     * @throws PurchaseFailedException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function purchase()
    {
        $mobile = $this->extractDetails('mobile');
        $data = array(
            'price' => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1), // convert to rial
            'callback_url' => $this->settings->callbackUrl,
            'buyer_phone' => $mobile,
            'client_id' => $this->invoice->getUuid(),
        );

        $response = $this->client->request(
            'POST',
            $this->settings->apiPurchaseUrl,
            [
                'headers' => [
                    "Authorization" => "Api-Key {$this->settings->authToken}",
                    "Accept" => "application/json",
                ],
                "json" => $data,
                "http_errors" => false,
            ]
        );
        $body = json_decode($response->getBody()->getContents(), true);
        $statusCode = (int)$response->getStatusCode();

        if (!in_array($statusCode, [200, 201])) {
            throw new PurchaseFailedException($body['detail']);
        }


        $this->invoice->transactionId($body['token']);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     */
    public function pay(): RedirectionForm
    {
        $payUrl = $this->invoice->getDetail("payment_link") . $this->invoice->getTransactionId();

        return $this->redirectWithForm($payUrl, [], 'GET');
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify(): ReceiptInterface
    {
        $data = [
            'api' => $this->settings->merchantId,
        ];

        $response = $this->client->request(
            'POST',
            $this->settings->apiVerificationUrl,
            [
                'headers' => [
                    "Authorization" => "Api-Key {$this->settings->authToken}",
                    "Accept" => "application/json",
                ],
                "json" => $data,
                "http_errors" => false,
            ]
        );
        $body = json_decode($response->getBody()->getContents(), true);
        $statusCode = (int)$response->getStatusCode();

        if (!in_array($statusCode, [200, 201]) && !$body['is_paid']) {
            $this->notVerified($body['detail']);
        }


        return $this->createReceipt($body['payment']['reference_number'], $body);
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     * @param $body
     *
     * @return Receipt
     */
    protected function createReceipt($referenceId, $body)
    {
        $receipt = new Receipt('sama', $referenceId);
        $receipt->detail([
            'isPaid' => $body['is_paid'],
            'fee' => $body['fee'],
            'transactionCode' => $body['payment']['transaction_code'],
            'requestId' => $body['payment']['request_id'],
            'paymentReqId' => $body['paymentReqId'],
            'paymentId' => $body['paymentId'],
        ]);

        return $receipt;
    }

    /**
     * Trigger an exception
     *
     * @param $status
     *
     * @throws InvalidPaymentException
     */
    private function notVerified($status)
    {
        $translations = array(
            40001 => 'شماره فروشنده پیدا نشد',
            40002 => 'شماره خریدار پیدا نشد',
            40003 => 'آدرس url توکن یبعانه معتبر نیست',

        );

        if (array_key_exists($status, $translations)) {
            throw new InvalidPaymentException($translations[$status], (int)$status);
        } else {
            throw new InvalidPaymentException('تراکنش با خطا مواجه شد.', (int)$status);
        }
    }
}

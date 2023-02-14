<?php

namespace Armincms\Paypal;

use Armincms\Arminpay\Contracts\Billing;
use Armincms\Arminpay\Contracts\Gateway;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Text;

class Paypal implements Gateway
{
    /**
     * Construcy the instance.
     *
     * @param  array  $config
     */
    public function __construct(protected array $config = [])
    {
    }

    /**
     * Make payment for the given Billing.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \InvalidArgumentException
     */
    public function pay(Request $request, Billing $billing)
    {
        $response = Http::withToken($this->generateAccessToken())
            ->asJson()
            ->throwUnless(fn ($response) => $response->successful())
            ->post($this->requestEndpoint().'/v2/checkout/orders', [
                "intent" => "CAPTURE",
                "reference_id" => $billing->getIdentifier(),
                "purchase_units" => [
                    [
                        "reference_id" => $billing->getIdentifier(),
                        "amount" => [
                            "value" => (string) $billing->amount(),
                            "currency_code" => 'USD',
                        ]
                    ]
                ]
        ]);

        $billing->setPayload('orderId', $response->json('id'))->save();

        return array_merge($response->json(), ['trackingCode' => $billing->getIdentifier()]);
    }

    /**
     * Verify the payment for the given Billing.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \InvalidArgumentException
     */
    public function verify(Request $request, Billing $billing)
    {
        return Http::withToken($this->generateAccessToken())
            ->asJson()
            ->throwUnless(fn ($response) => $response->successful())
            ->send('POST', $this->requestEndpoint()."/v2/checkout/orders/{$billing->getPayload('orderId')}/capture")
            ->json('purchase_units.0.payments.captures.0.id');
    }

    /**
     * Returns configuration fields.
     *
     * @return array
     */
    public function generateClientToken(Request $request): string
    {
        return Http::withHeaders(['Accept-Language' => 'en_US'])
            ->withToken($this->generateAccessToken())
            ->asJson()
            ->send('POST', $this->requestEndpoint().'/v1/identity/generate-token')
            ->json('client_token');
    }

    public function generateAccessToken(): string
    {

        return Http::withBody('grant_type=client_credentials', 'body')
            ->withBasicAuth($this->clientId(), $this->secret())
            ->post($this->requestEndpoint().'/v1/oauth2/token')
            ->json('access_token');
    }

    /**
     * Returns configuration fields.
     *
     * @return array
     */
    public function fields(Request $request): array
    {
        return [
            Text::make(__('Client ID'), 'client_id')->required()->rules('required'),
            Text::make(__('App Secret'), 'secret')->required()->rules('required'),
            Boolean::make(__('Sandbox'), 'sandbox')->default(false),
        ];
    }

    /**
     * Returns configuration fields.
     *
     * @return array
     */
    public function serializeForWidget(Request $request): array
    {
        return [
            'clientId' => $this->clientId(),
            'clientToken' => $this->generateClientToken($request),
        ];
    }

    public function requestEndpoint(): string
    {
        return data_get($this->config, 'sandbox') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    /**
     * Returns configuration fields.
     *
     * @return array
     */
    public function clientId(): string
    {
        return strval(data_get($this->config, 'client_id'));
    }

    /**
     * Returns configuration fields.
     *
     * @return array
     */
    public function secret(): string
    {
        return strval(data_get($this->config, 'secret'));
    }
}

<?php

/**
 * Class boxberryShippingDraftPackage
 */
class boxberryShippingDraftPackage
{
    /**
     * @var boxberryShipping|null
     */
    protected $bxb = null;

    /**
     * @var waOrder
     */
    protected $order;

    /**
     * @var array
     */
    protected $shipping_data;

    /**
     * boxberryShippingDraftPackage constructor.
     * @param boxberryShipping $bxb
     * @param waOrder $order
     * @param array $shipping_data
     */
    public function __construct(boxberryShipping $bxb, waOrder $order, $shipping_data = [])
    {
        $this->bxb = $bxb;
        $this->order = $order;
        $this->shipping_data = $shipping_data;
    }

    /**
     * @return array
     */
    public function createDraft()
    {
        $data = [
            'order_id'     => $this->getOrderId(),
            'delivery_sum' => $this->order->shipping,
            'payment_sum'  => $this->order->total,
            'vid'          => $this->getDeliveryType(),
            'items'        => $this->getItems(),
            'weights'      => [
                'weight' => $this->bxb->getParcelWeight(),
            ],
            'issue'        => $this->bxb->issuance,
            'sender_name'  => $this->bxb->notification_name
        ];

        // If the number is saved, then you need to update
        if (!empty($this->order->shipping_data['original_track_number'])) {
            $data['updateByTrack'] = $this->order->shipping_data['original_track_number'];
        }

        $declared_price = $this->bxb->declared_price;
        if ($declared_price) {
            $data['price'] = $this->bxb->getAssessedPrice();
        }

        if ($this->isCourierShipping()) {
            $data = $this->extendByCourier($data);
        }

        $data['shop'] = [
            'name'  => $this->getPointCode(),
            'name1' => $this->bxb->targetstart
        ];

        $data['customer'] = [
            'fio'   => $this->order->getContactField('name'),
            'phone' => $this->order->getContactField('phone'),
            'email' => $this->order->getContactField('email'),
        ];

        return $this->sendCreateDraftRequest($data);
    }


    /**
     * @return string
     */
    protected function getPointCode()
    {
        $result = '';

        if ($this->isPointShipping()) {
            $rate_id = $this->order->shipping_rate_id;
            $rate_data = explode(boxberryShippingCalculatePoints::getVariantSeparator(), $rate_id);

            $result = end($rate_data);
        }
        return $result;
    }

    /**
     * Adds required fields for the courier
     *
     * @param $data
     * @return array
     */
    protected function extendByCourier($data)
    {
        $shipping_address = $this->order->shipping_address;

        $data['kurdost'] = [
            'index'    => ifset($shipping_address, 'zip', ''),
            'addressp' => ifset($shipping_address, 'street', ''),
            'comentk'  => $this->order->comment,
        ];

        $city = ifset($shipping_address, 'city', '');
        $region = ifset($shipping_address, 'region_name', '');

        $data['kurdost']['citi'] = $region.', '.$city;

        return $data;
    }

    /**
     * @return bool|int
     */
    protected function getDeliveryType()
    {
        $type = false;
        if ($this->isPointShipping()) {
            $type = 1;
        } elseif ($this->isCourierShipping()) {
            $type = 2;
        }

        return $type;
    }

    /**
     * @return bool
     */
    protected function isCourierShipping()
    {
        $result = false;
        $rate_id = $this->order->shipping_rate_id;

        if (strpos($rate_id, boxberryShippingCalculateCourier::VARIANT_PREFIX) !== false) {
            $result = true;
        }

        return $result;
    }

    /**
     * @return bool
     */
    protected function isPointShipping()
    {
        $result = false;
        $rate_id = $this->order->shipping_rate_id;

        if (strpos($rate_id, boxberryShippingCalculatePoints::VARIANT_PREFIX) !== false) {
            $result = true;
        }

        return $result;
    }

    /**
     * @return array
     */
    protected function getItems()
    {
        $result = [];
        $items = $this->order->items;

        foreach ($items as $item) {
            $bxb_item = [
                'id'       => $item['id'],
                'name'     => $item['name'],
                'price'    => round($item['price'] - $item['discount'], 2),
                'quantity' => $item['quantity'],
                'UnitName' => 'шт.'
            ];

            if (is_numeric($item['tax_rate'])) {
                $bxb_item['nds'] = $item['tax_rate'];
            }

            $result[] = $bxb_item;
        }
        return $result;
    }

    /**
     * Validates the request and sends a request to create a draft
     *
     * @param $data
     * @return array
     */
    protected function sendCreateDraftRequest($data)
    {
        $error = $this->validateRequest($data);
        $result = [];

        if (!$error) {
            $api_manager = new boxberryShippingApiManager($this->bxb->token, $this->bxb->api_url);
            $request = ['sdata' => json_encode($data)];
            $send = $api_manager->createDraft($request);

            if (!empty($send['track'])) {
                $result = [
                    'original_track_number' => $send['track'],
                    'tracking_number'       => $send['track'],
                    'view_data'             => $this->getViewData($send['track']),
                ];
            }
        }

        return $result;
    }

    /**
     * @param string $track
     * @return string
     */
    protected function getViewData($track)
    {
        $url = $this->bxb->api_url;
        $url = parse_url($url, PHP_URL_HOST);
        $url = 'https://'.$url."?act=send&sub=info&F_SEARCH=%s";

        $template = "Создан заказ в личном кабинете Boxberry <a href='{$url}' target='_blank'>№%s<i class='icon16 new-window'></i></a>";
        if (!empty($this->order->shipping_data['original_track_number'])) {
            $template = "Обновлен заказ в личном кабинете Boxberry <a href='{$url}' target='_blank'>№%s<i class='icon16 new-window'></i></a>";
        }

        $template = str_replace('%s', $track, $template);
        return $template;
    }

    /**
     * Checks the final request for all required fields
     *
     * @param $data
     * @return bool
     */
    protected function validateRequest($data)
    {
        $error = false;

        if (empty($data['order_id']) || empty($data['vid']) || empty($data['shop']['name1'])) {
            $error = true;
        }

        if ($this->isCourierShipping() && (empty($data['kurdost']['citi']) || empty($data['kurdost']['addressp']))) {
            $error = true;
        }

        if ($this->isPointShipping() && empty($data['shop']['name'])) {
            $error = true;
        }

        if (empty($data['customer']['fio']) || empty($data['customer']['phone'])) {
            $error = true;
        }

        if (ifset($data, 'weights', 'weight', 0) <= 0) {
            $error = true;
        }

        return $error;
    }

    /**
     * Valid character set: a-z(A-Z), 0-9, а-я(А-Я), ёЁ, dash(-), forward slash(/), dot(.), comma(,), underscore(_), №, space
     * Max length: 35 symbols
     *
     * @return string
     */
    protected function getOrderId()
    {
        $order_id = $this->order->id_str;

        /** @noinspection RegExpRedundantEscape */
        $result = preg_replace('/[^a-zA-Z0-9а-яА-ЯёЁ\-.,_\/№ ]/u', '', $order_id);

        if (!$result) {
            $result = $this->order->id;
        }

        $result = substr($result, 0, 35);

        return $result;
    }
}
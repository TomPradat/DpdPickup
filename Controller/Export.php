<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia	                                                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : info@thelia.net                                                      */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      This program is free software; you can redistribute it and/or modify         */
/*      it under the terms of the GNU General Public License as published by         */
/*      the Free Software Foundation; either version 3 of the License                */
/*                                                                                   */
/*      This program is distributed in the hope that it will be useful,              */
/*      but WITHOUT ANY WARRANTY; without even the implied warranty of               */
/*      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the                */
/*      GNU General Public License for more details.                                 */
/*                                                                                   */
/*      You should have received a copy of the GNU General Public License            */
/*	    along with this program. If not, see <http://www.gnu.org/licenses/>.         */
/*                                                                                   */
/*************************************************************************************/

namespace DpdPickup\Controller;

use DpdPickup\Form\ExportExaprintSelection;
use DpdPickup\DpdPickup;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Translation\Translator;
use DpdPickup\Model\OrderAddressIcirelaisQuery;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Log\Tlog;
use Thelia\Model\AddressQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderAddressQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;

/**
 * Class Export
 * @package DpdPickup\Controller
 * @author Thelia <info@thelia.net>
 * @original_author etienne roudeix <eroudeix@openstudio.fr>
 * @contributor Etienne Perriere <eperriere@openstudio.fr>
 */
class Export extends BaseAdminController
{
    // L'arrivée de Maitre Guigit détrône les anciens maitres pour corriger le soucis de json qui se supprime à chaque composer install
    // Esclaves : Ex Maitre Roudeix @ Espeche
    public static function harmonise($value, $type, $len)
    {
        switch ($type) {
            case 'numeric':
                $value = (string)$value;
                if (mb_strlen($value, 'utf8') > $len) {
                    $value = substr($value, 0, $len);
                }
                for ($i = mb_strlen($value, 'utf8'); $i < $len; $i++) {
                    $value = '0' . $value;
                }
                break;
            case 'alphanumeric':
                $value = (string)$value;
                if (mb_strlen($value, 'utf8') > $len) {
                    $value = substr($value, 0, $len);
                }
                for ($i = mb_strlen($value, 'utf8'); $i < $len; $i++) {
                    $value .= ' ';
                }
                break;
            case 'float':
                if (!preg_match("#\d{1,6}\.\d{1,}#", $value)) {
                    $value = str_repeat("0", $len - 3) . ".00";
                } else {
                    $value = explode(".", $value);
                    $int = self::harmonise($value[0], 'numeric', $len - 3);
                    $dec = substr($value[1], 0, 2) . "." . substr($value[1], 2, strlen($value[1]));
                    $dec = (string)ceil(floatval($dec));
                    $dec = str_repeat("0", 2 - strlen($dec)) . $dec;
                    $value = $int . "." . $dec;
                }
                break;
        }

        return $value;
    }

    public function exportfile()
    {
        if (null !== $response = $this->checkAuth(
                array(AdminResources::MODULE),
                array('DpdPickup'),
                AccessManager::UPDATE
            )) {
            return $response;
        }

        $keys = array(
            DpdPickup::CONF_EXA_NAME,
            DpdPickup::CONF_EXA_ADDR,
            DpdPickup::CONF_EXA_ZIPCODE,
            DpdPickup::CONF_EXA_CITY,
            DpdPickup::CONF_EXA_TEL,
            DpdPickup::CONF_EXA_MOBILE,
            DpdPickup::CONF_EXA_MAIL,
            DpdPickup::CONF_EXA_EXPCODE
        );
        $valid = true;
        foreach ($keys as $key) {
            if (null === DpdPickup::getConfigValue($key)) {
                $valid = false;
                break;
            }
        }

        if (!$valid) {
            return Response::create(
                Translator::getInstance()->trans(
                    "The EXAPRINT configuration is missing. Please correct it.",
                    [],
                    DpdPickup::DOMAIN
                ),
                500
            );
        }

        $exp_name = DpdPickup::getConfigValue(DpdPickup::CONF_EXA_NAME);
        $exp_address1 = DpdPickup::getConfigValue(DpdPickup::CONF_EXA_ADDR);
        $exp_address2 = DpdPickup::getConfigValue(DpdPickup::CONF_EXA_ADDR2, '');
        $exp_zipcode = DpdPickup::getConfigValue(DpdPickup::CONF_EXA_ZIPCODE);
        $exp_city = DpdPickup::getConfigValue(DpdPickup::CONF_EXA_CITY);
        $exp_phone = DpdPickup::getConfigValue(DpdPickup::CONF_EXA_TEL);
        $exp_cellphone = DpdPickup::getConfigValue(DpdPickup::CONF_EXA_MOBILE);
        $exp_email = DpdPickup::getConfigValue(DpdPickup::CONF_EXA_MAIL);
        $exp_code = DpdPickup::getConfigValue(DpdPickup::CONF_EXA_EXPCODE);;
        $res = self::harmonise('$' . "VERSION=110", 'alphanumeric', 12) . "\r\n";

        $orders = OrderQuery::create()
            ->filterByDeliveryModuleId(DpdPickup::getModuleId())
            ->find();

        // FORM VALIDATION
        $form = new ExportExaprintSelection($this->getRequest());
        $status_id = null;
        try {
            $vform = $this->validateForm($form);
            $status_id = $vform->get("new_status_id")->getData();
            if (!preg_match("#^nochange|processing|sent$#", $status_id)) {
                throw new \Exception("Invalid status ID. Expecting nochange or processing or sent");
            }
        } catch (\Exception $e) {
            Tlog::getInstance()->error("Form dpdpickup.selection sent with bad infos. ");

            return Response::create(
                Translator::getInstance()->trans(
                    "Got invalid data : %err",
                    ['%err' => $e->getMessage()],
                    DpdPickup::DOMAIN
                ),
                500
            );
        }

        // For each selected order
        /** @var Order $order */
        foreach ($orders as $order) {
            $orderRef = str_replace(".", "-", $order->getRef());

            $collectionKey = array_search($orderRef, $vform->getData()['order_ref']);
            if (false !== $collectionKey
                && array_key_exists($collectionKey, $vform->getData()['order_ref_check'])
                && $vform->getData()['order_ref_check'][$collectionKey]) {

                // Get if the package is assured, how many packages there are & their weight
                $assur_package = array_key_exists($collectionKey, $vform->getData()['assur']) ? $vform->getData()['assur'][$collectionKey] : false;
                // $pkgNumber = array_key_exists($collectionKey, $vform->getData()['pkgNumber']) ? $vform->getData()['pkgNumber'][$collectionKey] : null;
                $pkgWeight = array_key_exists($collectionKey, $vform->getData()['pkgWeight']) ? $vform->getData()['pkgWeight'][$collectionKey] : null;

                // Check if status has to be changed
                if ($status_id == "processing") {
                    $event = new OrderEvent($order);
                    $status = OrderStatusQuery::create()
                        ->findOneByCode(OrderStatus::CODE_PROCESSING);
                    $event->setStatus($status->getId());
                    $this->getDispatcher()->dispatch(TheliaEvents::ORDER_UPDATE_STATUS, $event);
                } elseif ($status_id == "sent") {
                    $event = new OrderEvent($order);
                    $status = OrderStatusQuery::create()
                        ->findOneByCode(OrderStatus::CODE_SENT);
                    $event->setStatus($status->getId());
                    $this->getDispatcher()->dispatch(TheliaEvents::ORDER_UPDATE_STATUS, $event);
                }

                //Get invoice address
                $address = OrderAddressQuery::create()
                    ->findPK($order->getInvoiceOrderAddressId());

                //Get Customer object
                $customer = CustomerQuery::create()
                    ->findPK($order->getCustomerId());

                //Get OrderAddressDpdPickup object
                $icirelais_code = OrderAddressIcirelaisQuery::create()
                    ->findPK($order->getDeliveryOrderAddressId());

                if ($icirelais_code !== null) {

                    // Get Customer's cellphone
                    if (null == $cellphone = $address->getCellphone())
                    {
                        $address->getPhone();
                    }

                    //Weight & price calc
                    $price = 0;
                    $price = $order->getTotalAmount($price, false); // tax = 0 && include postage = flase

                    $pkgWeight = floor($pkgWeight * 100);

                    $assur_price = ($assur_package == 'true') ? $price : 0;
                    $date_format = date("d/m/y", $order->getUpdatedAt()->getTimestamp());

                    $res .= self::harmonise($order->getRef(), 'alphanumeric', 35);              // Order ref
                    $res .= self::harmonise("", 'alphanumeric', 2);
                    $res .= self::harmonise($pkgWeight, 'numeric', 8);                          // Package weight
                    $res .= self::harmonise("", 'alphanumeric', 15);
                    $res .= self::harmonise($address->getLastname(), 'alphanumeric', 35);       // Charged customer
                    $res .= self::harmonise($address->getFirstname(), 'alphanumeric', 35);
                    $res .= self::harmonise($address->getAddress2(), 'alphanumeric', 35);       // Invoice address info
                    $res .= self::harmonise($address->getAddress3(), 'alphanumeric', 35);
                    $res .= self::harmonise("", 'alphanumeric', 35);
                    $res .= self::harmonise("", 'alphanumeric', 35);
                    $res .= self::harmonise($address->getZipcode(), 'alphanumeric', 10);        // Invoice address
                    $res .= self::harmonise($address->getCity(), 'alphanumeric', 35);
                    $res .= self::harmonise("", 'alphanumeric', 10);
                    $res .= self::harmonise($address->getAddress1(), 'alphanumeric', 35);
                    $res .= self::harmonise("", 'alphanumeric', 10);
                    $res .= self::harmonise("F", 'alphanumeric', 3);                            // Default invoice country code
                    $res .= self::harmonise($address->getPhone(), 'alphanumeric', 30);          // Invoice phone
                    $res .= self::harmonise("", 'alphanumeric', 15);
                    $res .= self::harmonise($exp_name, 'alphanumeric', 35);                     // Expeditor name
                    $res .= self::harmonise($exp_address2, 'alphanumeric', 35);                 // Expeditor address
                    $res .= self::harmonise("", 'alphanumeric', 140);
                    $res .= self::harmonise($exp_zipcode, 'alphanumeric', 10);
                    $res .= self::harmonise($exp_city, 'alphanumeric', 35);
                    $res .= self::harmonise("", 'alphanumeric', 10);
                    $res .= self::harmonise($exp_address1, 'alphanumeric', 35);
                    $res .= self::harmonise("", 'alphanumeric', 10);
                    $res .= self::harmonise("F", 'alphanumeric', 3);                            // Default expeditor country code
                    $res .= self::harmonise($exp_phone, 'alphanumeric', 30);                    // Expeditor phone
                    $res .= self::harmonise("", 'alphanumeric', 35);                            // Order comment 1
                    $res .= self::harmonise("", 'alphanumeric', 35);                            // Order comment 2
                    $res .= self::harmonise("", 'alphanumeric', 35);                            // Order comment 3
                    $res .= self::harmonise("", 'alphanumeric', 35);                            // Order comment 4
                    $res .= self::harmonise($date_format.' ', 'alphanumeric', 10);              // Date
                    $res .= self::harmonise($exp_code, 'numeric', 8);                           // Expeditor DPD code
                    $res .= self::harmonise("", 'alphanumeric', 35);                            // Bar code
                    $res .= self::harmonise($customer->getRef(), 'alphanumeric', 35);           // Customer ref
                    $res .= self::harmonise("", 'alphanumeric', 29);
                    $res .= self::harmonise($assur_price, 'float', 9);                          // Insured value
                    $res .= self::harmonise("", 'alphanumeric', 8);
                    $res .= self::harmonise($customer->getId(), 'alphanumeric', 35);            // Customer ID
                    $res .= self::harmonise("", 'alphanumeric', 46);
                    $res .= self::harmonise($exp_email, 'alphanumeric', 80);                    // Expeditor email
                    $res .= self::harmonise($exp_cellphone, 'alphanumeric', 35);                // Expeditor cellphone
                    $res .= self::harmonise($customer->getEmail(), 'alphanumeric', 80);         // Customer email
                    $res .= self::harmonise($cellphone, 'alphanumeric', 35);                    // Invoice cellphone
                    $res .= self::harmonise("", 'alphanumeric', 96);
                    $res .= self::harmonise($icirelais_code->getCode(), 'alphanumeric', 8);     // DPD relay ID

                    $res .= "\r\n";
                }
            }
        }

        $response = new Response(
            utf8_decode(mb_strtoupper($res)),
            200,
            array(
                'Content-Type' => 'application/csv-tab-delimited-table;charset=iso-8859-1',
                'Content-disposition' => 'filename=export.dat'
            )
        );

        return $response;
    }
}

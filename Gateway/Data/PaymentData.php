<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Infracommerce\PagarmeDebugger\Gateway\Data;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use PHPUnit\Framework\TestCase;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order;
/**
 * Interface PaymentDataObjectInterface
 * @package Magento\Payment\Gateway\Data
 * @api
 * @since 100.0.2
 */
class PaymentData extends TestCase implements PaymentDataObjectInterface
{
    public function getOrder() {
        return parent::createMock(Payment::class);
    }

    public function getPayment() {
        $order = parent::createMock(Order::class);
        $test = parent::getMockBuilder(Payment::class)
            ->setMethods(['getOrder'])
            ->disableOriginalConstructor()
            ->getMock();

        $test
            ->expects($this->any())
            ->method('getOrder')
            ->willReturn($order);
        return $test;
    }
}

<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Infracommerce\PagarmeDebugger\Rewrite\Pagarme\Pagarme\Gateway\Transaction\Base\Command;

use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception as M2WebApiException;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Pagarme\Core\Kernel\Abstractions\AbstractModuleCoreSetup as MPSetup;
use Pagarme\Core\Kernel\Abstractions\AbstractPlatformOrderDecorator;
use Pagarme\Core\Kernel\Interfaces\PlatformOrderInterface;
use Pagarme\Core\Kernel\Services\OrderLogService;
use Pagarme\Core\Kernel\Services\OrderService;
use Pagarme\Core\Recurrence\Services\SubscriptionService;
use Pagarme\Pagarme\Concrete\Magento2CoreSetup;
use Pagarme\Pagarme\Model\Ui\CreditCard\ConfigProvider;
use Pagarme\Pagarme\Model\Ui\TwoCreditCard\ConfigProvider as TwoCreditCardConfigProvider;
use Psr\Log\LoggerInterface;
use Infracommerce\PagarmeDebugger\Helper\Data;

class InitializeCommand extends \Pagarme\Pagarme\Gateway\Transaction\Base\Command\InitializeCommand
{

    /**
     * @param LoggerInterface $loggerInterface
     * @param Data $scopeConfig
     */
    public function __construct(
        LoggerInterface $loggerInterface,
        Data            $scopeConfig
    ){
        $this->logger = $loggerInterface;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param array $commandSubject
     * @return $this
     */
    public function execute(array $commandSubject)
    {
        /** @var \Magento\Framework\DataObject $stateObject */
        $stateObject = $commandSubject['stateObject'];

        $paymentDO = SubjectReader::readPayment($commandSubject);

        $payment = $paymentDO->getPayment();

        if ($this->scopeConfig->isActive()) {
            $this->logger->debug("line 48 Pagarme Checkout Debug payment json: ". json_encode($payment->getData()));
        }

        if (!$payment instanceof Payment) {
            throw new \LogicException('Order Payment should be provided');
        }
        $orderResult = $this->doCoreDetour($payment);

        if ($this->scopeConfig->isActive()) {
            $this->logger->debug("line 55 Pagarme Checkout Debug orderResult json: " . json_encode($orderResult->getData()));
        }

        if ($orderResult !== false) {
            $orderResult->loadByIncrementId(
                $orderResult->getIncrementId()
            );

            $stateObject->setData(
                OrderInterface::STATE,
                $orderResult->getState()->getState()
            );
            $stateObject->setData(
                OrderInterface::STATUS,
                $orderResult->getStatus()
            );
            return $this;
        }

        $payment->getOrder()->setCanSendNewEmailFlag(true);
        $baseTotalDue = $payment->getOrder()->getBaseTotalDue();
        $totalDue = $payment->getOrder()->getTotalDue();
        $payment->authorize(true, $baseTotalDue);
        $payment->setAmountAuthorized($totalDue);
        $payment->setBaseAmountAuthorized($payment->getOrder()->getBaseTotalDue());
        $customStatus = $payment->getData('custom_status');

        $stateObject->setData(OrderInterface::STATE, Order::STATE_PENDING_PAYMENT);

        if ($payment->getMethod() === ConfigProvider::CODE || $payment->getMethod() === TwoCreditCardConfigProvider::CODE) {
            $stateObject->setData(OrderInterface::STATE, $customStatus->getData('state'));
            $stateObject->setData(OrderInterface::STATUS, $customStatus->getData('status'));
        }

        if ($payment->getMethod() != ConfigProvider::CODE) {
            $stateObject->setData(OrderInterface::STATUS, $payment->getMethodInstance()->getConfigData('order_status'));
        }

        $stateObject->setData('is_notified', false);

        return $this;
    }

    /** @return AbstractPlatformOrderDecorator */
    private function doCoreDetour($payment)
    {
        $order =  $payment->getOrder();

        if ($this->scopeConfig->isActive()) {
            $this->logger->debug("line 102 Pagarme Checkout Debug order id: " . $order->getIncrementId());
        }

        $log = new OrderLogService();

        Magento2CoreSetup::bootstrap();

        $platformOrderDecoratorClass = MPSetup::get(
            MPSetup::CONCRETE_PLATFORM_ORDER_DECORATOR_CLASS
        );

        $platformPaymentMethodDecoratorClass = MPSetup::get(
            MPSetup::CONCRETE_PLATFORM_PAYMENT_METHOD_DECORATOR_CLASS
        );

        /** @var PlatformOrderInterface $orderDecorator */
        $orderDecorator = new $platformOrderDecoratorClass();
        $orderDecorator->setPlatformOrder($order);

        $paymentMethodDecorator = new $platformPaymentMethodDecoratorClass();
        $paymentMethodDecorator->setPaymentMethod($orderDecorator);

        $orderDecorator->setPaymentMethod($paymentMethodDecorator->getPaymentMethod());

        if ($this->scopeConfig->isActive()) {
            $this->logger->debug("line 125 Pagarme Checkout Debug orderDecorator json: " . json_encode($orderDecorator->getData()));
        }

        $quote = $orderDecorator->getQuote();

        if ($this->scopeConfig->isActive()) {
            $this->logger->debug("line 129 Pagarme Checkout Debug quoteDecorator json: " . json_encode($quote->getData()));
        }

        try {
            $quoteSuccess = $quote->getCustomerNote();

            if ($this->scopeConfig->isActive()) {
                $this->logger->debug("line 153 Pagarme Checkout Debug quoteDecorator json: " . json_encode($quoteSuccess));
            }

            if ($quoteSuccess === 'pagarme-processing') {
                $log->orderInfo(
                    $orderDecorator->getCode(),
                    "Quote already used, order id duplicated. Customer Note: {$quoteSuccess}"
                );
                throw new \Exception("Quote already used, order id duplicated.");
            }

            $quote->setCustomerNote('pagarme-processing');
            $quote->save();

            $log->orderInfo(
                $orderDecorator->getCode(),
                "Changing status quote to processing."
            );

            $subscriptionService = new SubscriptionService();
            $isSubscription = $subscriptionService->isSubscription($orderDecorator);

            if ($isSubscription) {
                $subscriptionService->createSubscriptionAtPagarme($orderDecorator);
            }

            if (!$isSubscription) {
                $orderService = new OrderService();
                $orderService->createOrderAtPagarme($orderDecorator);
            }

            $orderDecorator->save();

            return $orderDecorator;
        } catch (\Exception $e) {
            if ($this->scopeConfig->isActive()) {
                $this->logger->debug("line 133 Pagarme Checkout Debug exception: " . $e->getMessage());
            }

            $quote->setCustomerNote('');
            $quote->save();

            $message = "Order failed, changing status quote to failed. \n";
            $message .= "Error message: " . $e->getMessage();
            $log->orderInfo(
                $orderDecorator->getCode(),
                $message
            );

            throw new M2WebApiException(
                new Phrase($e->getMessage()),
                0,
                $e->getCode()
            );
        }
    }
}


<?php

declare(strict_types=1);

namespace App\Command\Dev;

use App\Exception\SoapCallFailedException;
use App\NumberConversion\NumberConversionClient;
use App\StockAvailability\Client\StockAvailabilityClient;
use App\StockAvailability\Client\StockAvailabilityFaultException;
use App\StockAvailability\Contract\CreateOrderRequest;
use App\StockAvailability\Contract\GetStockAvailabilityRequest;
use App\StockAvailability\Contract\OrderItem;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;
use function str_pad;

#[AsCommand(
    name: 'soap-availability:dev:demo',
    description: 'Call both operations of the stock availability service, trigger a fault, '
        . 'and call the public NumberConversion service.',
)]
final class DemoCommand extends Command
{
    private const string PARTNER_ID = 'demo-partner';

    private const int LABEL_WIDTH = 22;

    public function __construct(
        private readonly StockAvailabilityClient $stockAvailabilityClient,
        private readonly NumberConversionClient $numberConversionClient,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $availabilityRequest = new GetStockAvailabilityRequest();
        $availabilityRequest->partnerId = self::PARTNER_ID;
        $availabilityRequest->productCode = ['KEYB-01', 'MOUSE-01'];
        $availabilityResponse = $this->stockAvailabilityClient->getStockAvailability($availabilityRequest);
        foreach ($availabilityResponse->product as $productAvailability) {
            $this->writeStep($io, 'getStockAvailability', sprintf(
                '%-11s %4d pcs  %9s %s  %s',
                $productAvailability->productCode,
                $productAvailability->quantity,
                $productAvailability->price,
                $productAvailability->currency,
                $productAvailability->available ? 'available' : 'sold out',
            ));
        }

        $order = $this->stockAvailabilityClient->createOrder($this->buildSingleItemOrder('KEYB-01', 2));
        $this->writeStep($io, 'createOrder', sprintf(
            '%s  2x KEYB-01 = %s %s  (nothing is persisted)',
            $order->orderNumber,
            $order->totalPrice,
            $order->currency,
        ));

        try {
            $this->stockAvailabilityClient->createOrder($this->buildSingleItemOrder('NOPE', 1));
            $this->writeStep($io, 'createOrder NOPE', 'no fault: the catalog must not know NOPE');
        } catch (StockAvailabilityFaultException $exception) {
            $this->writeStep($io, 'createOrder NOPE', sprintf(
                'fault %s (productCode=%s): a fault envelope, not an HTTP error page:',
                $exception->getFaultCode()->value,
                $exception->getFault()->productCode ?? '-',
            ));
            $io->writeln('  ' . ($this->stockAvailabilityClient->getLastResponseXml() ?? '(no response recorded)'));
        }

        try {
            $this->writeStep($io, 'NumberToDollars', sprintf(
                '%s -> %s  (SOAP 1.2, dataaccess.com)',
                $order->totalPrice,
                $this->numberConversionClient->numberToDollars($order->totalPrice),
            ));
        } catch (SoapCallFailedException $exception) {
            $this->writeStep($io, 'NumberToDollars', sprintf(
                'unavailable (%s) - dataaccess.com is a free public service, everything above ran locally',
                $exception->getMessage(),
            ));
        }

        return Command::SUCCESS;
    }

    private function writeStep(SymfonyStyle $io, string $label, string $detail): void
    {
        $io->writeln(str_pad($label, self::LABEL_WIDTH) . $detail);
    }

    private function buildSingleItemOrder(string $productCode, int $quantity): CreateOrderRequest
    {
        $orderItem = new OrderItem();
        $orderItem->productCode = $productCode;
        $orderItem->quantity = $quantity;

        $orderRequest = new CreateOrderRequest();
        $orderRequest->partnerId = self::PARTNER_ID;
        $orderRequest->item = [$orderItem];

        return $orderRequest;
    }
}

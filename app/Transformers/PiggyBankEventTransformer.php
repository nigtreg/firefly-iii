<?php
/**
 * PiggyBankEventTransformer.php
 * Copyright (c) 2018 thegrumpydictator@gmail.com
 *
 * This file is part of Firefly III.
 *
 * Firefly III is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Firefly III is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Firefly III. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Transformers;


use FireflyIII\Models\PiggyBankEvent;
use FireflyIII\Models\TransactionCurrency;
use League\Fractal\TransformerAbstract;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Class PiggyBankEventTransformer
 */
class PiggyBankEventTransformer extends TransformerAbstract
{
    /**
     * List of resources possible to include
     *
     * @var array
     */
    protected $availableIncludes = ['piggy_bank', 'transactions'];
    /**
     * List of resources to automatically include
     *
     * @var array
     */
    protected $defaultIncludes = [];

    /** @var ParameterBag */
    protected $parameters;

    /**
     * BillTransformer constructor.
     *
     * @param ParameterBag $parameters
     */
    public function __construct(ParameterBag $parameters)
    {
        $this->parameters = $parameters;
    }

    /**
     * @param PiggyBankEvent $event
     *
     * @return array
     */
    public function transform(PiggyBankEvent $event): array
    {
        $account       = $event->piggyBank->account;
        $currencyId    = intval($account->getMeta('currency_id'));
        $decimalPlaces = 2;
        if ($currencyId > 0) {
            $currency      = TransactionCurrency::find($currencyId);
            $decimalPlaces = $currency->decimal_places;
        }

        $data = [
            'id'         => (int)$event->id,
            'updated_at' => $event->updated_at->toAtomString(),
            'created_at' => $event->created_at->toAtomString(),
            'amount'     => round($event->amount, $decimalPlaces),
            'links'      => [
                [
                    'rel' => 'self',
                    'uri' => '/piggy_bank_events/' . $event->id,
                ],
            ],
        ];

        return $data;
    }

}
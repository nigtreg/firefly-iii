<?php

/**
 * PiggyBankRepository.php
 * Copyright (c) 2019 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace FireflyIII\Repositories\PiggyBank;

use Carbon\Carbon;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Factory\PiggyBankFactory;
use FireflyIII\Models\Account;
use FireflyIII\Models\Attachment;
use FireflyIII\Models\Note;
use FireflyIII\Models\PiggyBank;
use FireflyIII\Models\PiggyBankRepetition;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Journal\JournalRepositoryInterface;
use FireflyIII\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Class PiggyBankRepository.
 */
class PiggyBankRepository implements PiggyBankRepositoryInterface
{
    use ModifiesPiggyBanks;

    private User $user;

    public function destroyAll(): void
    {
        Log::channel('audit')->info('Delete all piggy banks through destroyAll');
        $this->user->piggyBanks()->delete();
    }

    public function findPiggyBank(?int $piggyBankId, ?string $piggyBankName): ?PiggyBank
    {
        app('log')->debug('Searching for piggy information.');

        if (null !== $piggyBankId) {
            $searchResult = $this->find($piggyBankId);
            if (null !== $searchResult) {
                app('log')->debug(sprintf('Found piggy based on #%d, will return it.', $piggyBankId));

                return $searchResult;
            }
        }
        if (null !== $piggyBankName) {
            $searchResult = $this->findByName($piggyBankName);
            if (null !== $searchResult) {
                app('log')->debug(sprintf('Found piggy based on "%s", will return it.', $piggyBankName));

                return $searchResult;
            }
        }
        app('log')->debug('Found nothing');

        return null;
    }

    public function find(int $piggyBankId): ?PiggyBank
    {
        // phpstan doesn't get the Model.
        return $this->user->piggyBanks()->find($piggyBankId); // @phpstan-ignore-line
    }

    /**
     * Find by name or return NULL.
     */
    public function findByName(string $name): ?PiggyBank
    {
        return $this->user->piggyBanks()->where('piggy_banks.name', $name)->first(['piggy_banks.*']);
    }

    public function getAttachments(PiggyBank $piggyBank): Collection
    {
        $set  = $piggyBank->attachments()->get();

        /** @var \Storage $disk */
        $disk = \Storage::disk('upload');

        return $set->each(
            static function (Attachment $attachment) use ($disk) {
                $notes                   = $attachment->notes()->first();
                $attachment->file_exists = $disk->exists($attachment->fileName());
                $attachment->notes_text  = null !== $notes ? $notes->text : '';

                return $attachment;
            }
        );
    }

    /**
     * Get current amount saved in piggy bank.
     */
    public function getCurrentAmount(PiggyBank $piggyBank, ?Account $account = null): string
    {
        $sum = '0';
        foreach ($piggyBank->accounts as $current) {
            if (null !== $account && $account->id !== $current->id) {
                continue;
            }
            $amount = (string) $current->pivot->current_amount;
            $amount = '' === $amount ? '0' : $amount;
            $sum    = bcadd($sum, $amount);
        }
        Log::debug(sprintf('Current amount in piggy bank #%d ("%s") is %s', $piggyBank->id, $piggyBank->name, $sum));

        return $sum;
    }

    public function getRepetition(PiggyBank $piggyBank, bool $overrule = false): ?PiggyBankRepetition
    {
        if (false === $overrule) {
            throw new FireflyException('[b] Piggy bank repetitions are EOL.');
        }
        Log::warning('Piggy bank repetitions are EOL.');

        return $piggyBank->piggyBankRepetitions()->first();
    }

    public function getEvents(PiggyBank $piggyBank): Collection
    {
        return $piggyBank->piggyBankEvents()->orderBy('date', 'DESC')->orderBy('id', 'DESC')->get();
    }

    /**
     * Used for connecting to a piggy bank.
     *
     * @throws FireflyException
     */
    public function getExactAmount(PiggyBank $piggyBank, PiggyBankRepetition $repetition, TransactionJournal $journal): string
    {
        throw new FireflyException('[c] Piggy bank repetitions are EOL.');
        app('log')->debug(sprintf('Now in getExactAmount(%d, %d, %d)', $piggyBank->id, $repetition->id, $journal->id));

        $operator          = null;
        $currency          = null;

        /** @var JournalRepositoryInterface $journalRepost */
        $journalRepost     = app(JournalRepositoryInterface::class);
        $journalRepost->setUser($this->user);

        /** @var AccountRepositoryInterface $accountRepos */
        $accountRepos      = app(AccountRepositoryInterface::class);
        $accountRepos->setUser($this->user);

        $defaultCurrency   = app('amount')->getDefaultCurrencyByUserGroup($this->user->userGroup);
        $piggyBankCurrency = $accountRepos->getAccountCurrency($piggyBank->account) ?? $defaultCurrency;

        app('log')->debug(sprintf('Piggy bank #%d currency is %s', $piggyBank->id, $piggyBankCurrency->code));

        /** @var Transaction $source */
        $source            = $journal->transactions()->with(['account'])->where('amount', '<', 0)->first();

        /** @var Transaction $destination */
        $destination       = $journal->transactions()->with(['account'])->where('amount', '>', 0)->first();

        // matches source, which means amount will be removed from piggy:
        if ($source->account_id === $piggyBank->account_id) {
            $operator = 'negative';
            $currency = $accountRepos->getAccountCurrency($source->account) ?? $defaultCurrency;
            app('log')->debug(sprintf('Currency will draw money out of piggy bank. Source currency is %s', $currency->code));
        }

        // matches destination, which means amount will be added to piggy.
        if ($destination->account_id === $piggyBank->account_id) {
            $operator = 'positive';
            $currency = $accountRepos->getAccountCurrency($destination->account) ?? $defaultCurrency;
            app('log')->debug(sprintf('Currency will add money to piggy bank. Destination currency is %s', $currency->code));
        }
        if (null === $operator || null === $currency) {
            app('log')->debug('Currency is NULL and operator is NULL, return "0".');

            return '0';
        }
        // currency of the account + the piggy bank currency are almost the same.
        // which amount from the transaction matches?
        $amount            = null;
        if ((int) $source->transaction_currency_id === $currency->id) {
            app('log')->debug('Use normal amount');
            $amount = app('steam')->{$operator}($source->amount); // @phpstan-ignore-line
        }
        if ((int) $source->foreign_currency_id === $currency->id) {
            app('log')->debug('Use foreign amount');
            $amount = app('steam')->{$operator}($source->foreign_amount); // @phpstan-ignore-line
        }
        if (null === $amount) {
            app('log')->debug('No match on currency, so amount remains null, return "0".');

            return '0';
        }

        app('log')->debug(sprintf('The currency is %s and the amount is %s', $currency->code, $amount));
        $room              = bcsub($piggyBank->target_amount, $repetition->current_amount);
        $compare           = bcmul($repetition->current_amount, '-1');

        if (0 === bccomp($piggyBank->target_amount, '0')) {
            // amount is zero? then the "room" is positive amount of we wish to add or remove.
            $room = app('steam')->positive($amount);
            app('log')->debug(sprintf('Room is now %s', $room));
        }

        app('log')->debug(sprintf('Will add/remove %f to piggy bank #%d ("%s")', $amount, $piggyBank->id, $piggyBank->name));

        // if the amount is positive, make sure it fits in piggy bank:
        if (1 === bccomp($amount, '0') && -1 === bccomp($room, $amount)) {
            // amount is positive and $room is smaller than $amount
            app('log')->debug(sprintf('Room in piggy bank for extra money is %f', $room));
            app('log')->debug(sprintf('There is NO room to add %f to piggy bank #%d ("%s")', $amount, $piggyBank->id, $piggyBank->name));
            app('log')->debug(sprintf('New amount is %f', $room));

            return $room;
        }

        // amount is negative and $currentamount is smaller than $amount
        if (-1 === bccomp($amount, '0') && 1 === bccomp($compare, $amount)) {
            app('log')->debug(sprintf('Max amount to remove is %f', $repetition->current_amount));
            app('log')->debug(sprintf('Cannot remove %f from piggy bank #%d ("%s")', $amount, $piggyBank->id, $piggyBank->name));
            app('log')->debug(sprintf('New amount is %f', $compare));

            return $compare;
        }

        return (string) $amount;
    }

    public function setUser(null|Authenticatable|User $user): void
    {
        if ($user instanceof User) {
            $this->user = $user;
        }
    }

    /**
     * Return note for piggy bank.
     */
    public function getNoteText(PiggyBank $piggyBank): string
    {
        /** @var null|Note $note */
        $note = $piggyBank->notes()->first();

        return (string) $note?->text;
    }

    /**
     * Also add amount in name.
     */
    public function getPiggyBanksWithAmount(): Collection
    {
        $set = $this->getPiggyBanks();

        /** @var PiggyBank $piggy */
        foreach ($set as $piggy) {
            $currentAmount = $this->getCurrentAmount($piggy);
            $piggy->name   = sprintf('%s (%s)',$piggy->name,app('amount')->formatAnything($piggy->transactionCurrency, $currentAmount, false));
        }

        return $set;
    }

    public function getPiggyBanks(): Collection
    {
        return PiggyBank::leftJoin('account_piggy_bank', 'account_piggy_bank.piggy_bank_id', '=', 'piggy_banks.id')
            ->leftJoin('accounts', 'accounts.id', '=', 'account_piggy_bank.account_id')
            ->where('accounts.user_id', auth()->user()->id)
            ->with(
                [
                    'objectGroups',
                ]
            )
            ->orderBy('piggy_banks.order', 'ASC')->distinct()->get(['piggy_banks.*'])
        ;
    }

    /**
     * Returns the suggested amount the user should save per month, or "".
     */
    public function getSuggestedMonthlyAmount(PiggyBank $piggyBank): string
    {
        $savePerMonth  = '0';
        $currentAmount = $this->getCurrentAmount($piggyBank);
        if (null !== $piggyBank->target_date && $currentAmount < $piggyBank->target_amount) {
            $now             = today(config('app.timezone'));
            $startDate       = null !== $piggyBank->start_date && $piggyBank->start_date->gte($now) ? $piggyBank->start_date : $now;
            $diffInMonths    = (int) $startDate->diffInMonths($piggyBank->target_date);
            $remainingAmount = bcsub($piggyBank->target_amount, $currentAmount);

            // more than 1 month to go and still need money to save:
            if ($diffInMonths > 0 && 1 === bccomp($remainingAmount, '0')) {
                $savePerMonth = bcdiv($remainingAmount, (string) $diffInMonths);
            }

            // less than 1 month to go but still need money to save:
            if (0 === $diffInMonths && 1 === bccomp($remainingAmount, '0')) {
                $savePerMonth = $remainingAmount;
            }
        }

        return $savePerMonth;
    }

    /**
     * Get for piggy account what is left to put in piggies.
     */
    public function leftOnAccount(PiggyBank $piggyBank, Account $account, Carbon $date): string
    {
        Log::debug(sprintf('leftOnAccount("%s","%s","%s")', $piggyBank->name, $account->name, $date->format('Y-m-d H:i:s')));
        $balance = app('steam')->balanceConvertedIgnoreVirtual($account, $date, $piggyBank->transactionCurrency);
        Log::debug(sprintf('Balance is: %s', $balance));

        /** @var Collection $piggies */
        $piggies = $account->piggyBanks;

        /** @var PiggyBank $current */
        foreach ($piggies as $current) {
            $amount  = $this->getCurrentAmount($current, $account);
            $balance = bcsub($balance, $amount);
            Log::debug(sprintf('Piggy bank: #%d with amount %s, balance is now %s', $current->id, $amount, $balance));
        }
        Log::debug(sprintf('Final balance is: %s', $balance));

        return $balance;
    }

    public function searchPiggyBank(string $query, int $limit): Collection
    {
        $search = PiggyBank::leftJoin('account_piggy_bank', 'account_piggy_bank.piggy_bank_id', '=', 'piggy_banks.id')
            ->leftJoin('accounts', 'accounts.id', '=', 'account_piggy_bank.account_id')
            ->where('accounts.user_id', auth()->user()->id)
            ->with(
                [
                    'objectGroups',
                ]
            )
            ->orderBy('piggy_banks.order', 'ASC')->distinct()
        ;
        if ('' !== $query) {
            $search->whereLike('piggy_banks.name', sprintf('%%%s%%', $query));
        }
        $search->orderBy('piggy_banks.order', 'ASC')
            ->orderBy('piggy_banks.name', 'ASC')
        ;

        return $search->take($limit)->get(['piggy_banks.*']);
    }

    #[\Override]
    public function purgeAll(): void
    {
        throw new FireflyException('TODO Not implemented');
    }

    #[\Override]
    public function resetOrder(): void
    {
        $factory       = new PiggyBankFactory();
        $factory->user = $this->user;
        $factory->resetOrder();
    }
}

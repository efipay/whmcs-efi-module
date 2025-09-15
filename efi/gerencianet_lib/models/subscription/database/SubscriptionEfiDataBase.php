<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use Exception;

/**
 * Classe EfiSubscriptionDatabase
 *
 * Esta classe fornece métodos para gerenciar assinaturas EFI e pagamentos agendados.
 */
class EfiSubscriptionDatabase
{
    /**
     * Cria as tabelas de assinatura EFI se elas não existirem.
     * Se a tabela tblsubscriptionefi já existir, remove o UNIQUE de relid (se presente).
     *
     * @return void
     */
    public static function createEfiSubscriptionTable()
    {
        try {
            // Criação da tabela tblsubscriptionefi
            if (!Capsule::schema()->hasTable('tblsubscriptionefi')) {
                Capsule::schema()->create('tblsubscriptionefi', function ($table) {
                    $table->increments('id');
                    $table->string('payment_method');
                    $table->string('customer', 10000);
                    $table->unsignedInteger('relid'); // sem UNIQUE
                });
                logActivity('Tabela tblsubscriptionefi criada com sucesso.');
            } else {
                // Tabela já existe: remover UNIQUE em relid, se houver
                self::dropUniqueRelidIfPresent();
            }

            // Criação da tabela tblschedulepaymentefi
            if (!Capsule::schema()->hasTable('tblschedulepaymentefi')) {
                Capsule::schema()->create('tblschedulepaymentefi', function ($table) {
                    $table->increments('id');
                    $table->unsignedInteger('invoiceid');
                    $table->unsignedInteger('relid');
                    $table->string('payment_token');
                    $table->date('date');
                    $table->foreign('invoiceid')->references('id')->on('tblinvoices')->onDelete('cascade');
                });
                logActivity('Tabela tblschedulepaymentefi criada com sucesso.');
            }
        } catch (QueryException $e) {
            logActivity('Erro ao criar as tabelas: ' . $e->getMessage());
            throw new QueryException($e->getSql(), $e->getBindings(), $e);
        } catch (Exception $e) {
            logActivity('Erro inesperado ao criar as tabelas: ' . $e->getMessage());
            throw new Exception('Erro inesperado ao criar as tabelas: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Remove o índice UNIQUE de relid em tblsubscriptionefi, se existir.
     *
     * @return void
     * @throws QueryException
     */
    private static function dropUniqueRelidIfPresent(): void
    {
        // Nome padrão gerado pelo Schema quando se usa ->unique() em relid
        $indexName = 'tblsubscriptionefi_relid_unique';

        // Obtém o nome do banco atual
        $dbName = Capsule::connection()->getConfig('database');

        // Verifica no information_schema se o índice existe
        $exists = Capsule::table('information_schema.statistics')
            ->where('table_schema', $dbName)
            ->where('table_name', 'tblsubscriptionefi')
            ->where('index_name', $indexName)
            ->count() > 0;

        if ($exists) {
            // Executa o DROP INDEX somente se existir
            Capsule::statement('ALTER TABLE `tblsubscriptionefi` DROP INDEX `' . $indexName . '`');
            logActivity('Índice UNIQUE removido: ' . $indexName . ' em tblsubscriptionefi.relid.');
        }
    }

    /**
     * Adiciona uma nova assinatura.
     *
     * @param string $paymentMethod
     * @param int    $relId
     * @param array  $customer
     *
     * @return bool
     */
    public static function addSubscription(string $paymentMethod, int $relId, array $customer): bool
    {
        try {
            $resultTblSubscription = Capsule::table('tblsubscriptionefi')->insert([
                'payment_method' => $paymentMethod,
                'relid'          => $relId,
                'customer'       => json_encode($customer),
            ]);
            logActivity("Assinatura adicionada com sucesso para o rel ID: $relId.");
            return ($resultTblSubscription > 0);
        } catch (QueryException $e) {
            logActivity('Erro ao adicionar assinatura: ' . $e->getMessage());
            throw new QueryException($e->getSql(), $e->getBindings(), $e);
        } catch (Exception $e) {
            logActivity('Erro inesperado ao adicionar assinatura: ' . $e->getMessage());
            throw new Exception('Erro inesperado ao adicionar assinatura: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Busca assinaturas por IDs de relacionamento (relId) e verifica se todas estão presentes na tabela.
     *
     * @param array $relIds
     *
     * @return bool Retorna true se todos os relIds estiverem na tabela, false caso contrário.
     */
    public static function areAllSubscriptionsPresent(array $relIds): bool
    {
        try {
            $subscriptions = Capsule::table('tblsubscriptionefi')
                ->whereIn('relid', $relIds)
                ->count();
            return ($subscriptions === count($relIds));
        } catch (QueryException $e) {
            logActivity('Erro ao buscar assinaturas: ' . $e->getMessage());
            throw new QueryException($e->getSql(), $e->getBindings(), $e);
        } catch (Exception $e) {
            logActivity('Erro inesperado ao buscar assinaturas: ' . $e->getMessage());
            throw new Exception('Erro inesperado ao buscar assinaturas: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Remove uma assinatura.
     *
     * @param int $relId
     *
     * @return void
     */
    public static function deleteSubscription($relId)
    {
        try {
            Capsule::table('tblsubscriptionefi')->where('relid', $relId)->delete();
            logActivity("Assinatura removida com sucesso para o rel ID: $relId.");
        } catch (QueryException $e) {
            logActivity('Erro ao remover assinatura: ' . $e->getMessage());
            throw new QueryException($e->getSql(), $e->getBindings(), $e);
        } catch (Exception $e) {
            logActivity('Erro inesperado ao remover assinatura: ' . $e->getMessage());
            throw new Exception('Erro inesperado ao remover assinatura: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Adiciona um novo pagamento agendado.
     *
     * @param int    $invoiceId
     * @param string $paymentToken
     * @param string $date
     * @param int    $relId
     *
     * @return bool
     */
    public static function addScheduledPayment($invoiceId, $paymentToken, $date, $relId)
    {
        try {
            $returnTblSchedule = Capsule::table('tblschedulepaymentefi')->insert([
                'invoiceid'     => $invoiceId,
                'payment_token' => $paymentToken,
                'date'          => $date,
                'relid'         => $relId,
            ]);
            logActivity("Pagamento agendado adicionado com sucesso para a fatura ID: $invoiceId.");
            return ($returnTblSchedule > 0);
        } catch (QueryException $e) {
            logActivity('Erro ao adicionar pagamento agendado: ' . $e->getMessage());
            throw new QueryException($e->getSql(), $e->getBindings(), $e);
        } catch (Exception $e) {
            logActivity('Erro inesperado ao adicionar pagamento agendado: ' . $e->getMessage());
            throw new Exception('Erro inesperado ao adicionar pagamento agendado: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Busca um pagamento agendado por ID de fatura.
     *
     * @param int $invoiceId
     *
     * @return mixed
     */
    public static function getScheduledPaymentByInvoiceId($invoiceId)
    {
        try {
            $payment = Capsule::table('tblschedulepaymentefi')->where('invoiceid', $invoiceId)->first();
            logActivity("Pagamento agendado buscado com sucesso para a fatura ID: $invoiceId.");
            return $payment;
        } catch (QueryException $e) {
            logActivity('Erro ao buscar pagamento agendado: ' . $e->getMessage());
            throw new QueryException($e->getSql(), $e->getBindings(), $e);
        } catch (Exception $e) {
            logActivity('Erro inesperado ao buscar pagamento agendado: ' . $e->getMessage());
            throw new Exception('Erro inesperado ao buscar pagamento agendado: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Remove um pagamento agendado.
     *
     * @param int $invoiceId
     *
     * @return void
     */
    public static function deleteScheduledPayment($invoiceId)
    {
        try {
            Capsule::table('tblschedulepaymentefi')->where('invoiceid', $invoiceId)->delete();
            logActivity("Pagamento agendado removido com sucesso para a fatura ID: $invoiceId.");
        } catch (QueryException $e) {
            logActivity('Erro ao remover pagamento agendado: ' . $e->getMessage());
            throw new QueryException($e->getSql(), $e->getBindings(), $e);
        } catch (Exception $e) {
            logActivity('Erro inesperado ao remover pagamento agendado: ' . $e->getMessage());
            throw new Exception('Erro inesperado ao remover pagamento agendado: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }
}

<?php

namespace TheITDept\WPSonar\API;

use GraphQL\Client;
use GraphQL\Mutation;
use GraphQL\Query;
use GraphQL\Results;
use GraphQL\Variable;

class SonarApi
{

    private string $url;
    private string $key;
    private Client $client;

    public static function make(string $url, string $key): self
    {
        return (new self())->setUrl($url)->setToken($key)->setupClient();
    }

    public function setupClient(): self
    {
        $this->client = new Client(
            $this->url,
            ['Authorization' => $this->key, 'Accept' => 'application/json'],
        );
        return $this;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;
        return $this;
    }

    public function setToken(string $key): self
    {
        $this->key = $key;
        return $this;
    }

    public function run(Query $query, bool $resultsAsArray = false, array $variables = []): Results
    {
        return $this->client->runQuery($query, $resultsAsArray, $variables);
    }

    // getCompanies returns an array of companies from Sonar.
    public function getCompanies(): array
    {
        $query = (new Query("companies"))
            ->setSelectionSet([
                "entities" =>
                    (new Query("entities"))
                        ->setSelectionSet([
                            "id",
                            "name",
                        ])

            ]);

        $results = $this->run($query);

        return collect($results->getData()->companies->entities ?? [])->mapWithKeys(function ($company) {
            return [$company->id => $company->name];
        })->toArray();
    }

    // getAccountStatuses returns an array of account statuses from Sonar.
    public function getAccountStatuses(): array
    {
        $query = (new Query("account_statuses"))
            ->setSelectionSet([
                "entities" =>
                    (new Query("entities"))
                        ->setSelectionSet([
                            "id",
                            "name",
                        ])

            ]);

        $results = $this->run($query);

        return collect($results->getData()->account_statuses->entities ?? [])->mapWithKeys(function ($account_status) {
            return [$account_status->id => $account_status->name];
        })->toArray();
    }

    // getAccountTypes returns an array of account types from Sonar.
    public function getAccountTypes(): array
    {
        $query = (new Query("account_types"))
            ->setSelectionSet([
                "entities" =>
                    (new Query("entities"))
                        ->setSelectionSet([
                            "id",
                            "name",
                        ])

            ]);

        $results = $this->run($query);

        return collect($results->getData()->account_types->entities ?? [])->mapWithKeys(function ($account_type) {
            return [$account_type->id => $account_type->name];
        })->toArray();
    }

    /*

     */
    public function createAddress(array $input)
    {
        $cra = (new Mutation("createServiceableAddress"))
            ->setVariables([new Variable('input', 'CreateServiceableAddressMutationInput', true)])
            ->setArguments(['input' => '$input'])
            ->setSelectionSet(['id']);

        $res = $this->run($cra, false, ['input' => $input]);
        return $res->getResults()->data->createServiceableAddress->id ?? null;
    }

    public function createAccount(array $input)
    {
        $cra = (new Mutation("createAccount"))
            ->setVariables([new Variable('input', 'CreateAccountMutationInput', true)])
            ->setArguments(['input' => '$input'])
            ->setSelectionSet(['id']);

        $res = $this->run($cra, false, ['input' => $input]);
        return $res->getResults()->data->createAccount->id ?? null;
    }
}
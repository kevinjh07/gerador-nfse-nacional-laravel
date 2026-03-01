<?php

namespace App\Services\Contracts;

interface NfseServiceInterface
{
    public function cancelar(string $idNota): bool;
    public function consultar(string $idNota): array;
}

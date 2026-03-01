<?php

namespace App\Services;

use App\Services\DTOs\DpsDTO;
use DOMDocument;

class DpsXmlBuilder
{
    private const NS_NFSE = 'http://www.sped.fazenda.gov.br/nfse';

    public function build(DpsDTO $dps): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;

        $root = $doc->createElementNS(self::NS_NFSE, 'DPS');
        $root->setAttribute('versao', '1.01');
        $doc->appendChild($root);

        // Montagem do ID rigorosa (código do município da empresa vem do .env NFSE_EMPRESA_MUNICIPIO)
        $cLocEmi = preg_replace('/[^0-9]/', '', $dps->empresa->codigoMunicipio) ?: '0000000';
        $numero = (string)(int)preg_replace('/[^0-9]/', '', $dps->identificacao->numero);
        $serie = (string)(int)preg_replace('/[^0-9]/', '', $dps->identificacao->serie);
        $idDps = 'DPS' . $cLocEmi . $dps->identificacao->ambiente .
                 str_pad(preg_replace('/[^0-9]/', '', $dps->empresa->cnpj), 14, '0', STR_PAD_LEFT) .
                 sprintf('%05d', $serie) . sprintf('%015d', $numero);

        $infDps = $doc->createElementNS(self::NS_NFSE, 'infDPS');
        $infDps->setAttribute('Id', $idDps);
        $root->appendChild($infDps);

        // dhEmi e dCompet no fuso America/Sao_Paulo (-03:00); o servidor da SEFIN compara em UTC-3 (E0008).
        $tzBrasilia = new \DateTimeZone('America/Sao_Paulo');
        $dtLocal = (new \DateTime('@' . $dps->identificacao->dataHoraEmissao->getTimestamp()))->setTimezone($tzBrasilia);
        $dhEmi = $dtLocal->format('Y-m-d\TH:i:sP');
        $this->addNode($doc, $infDps, 'tpAmb', (string)$dps->identificacao->ambiente);
        $this->addNode($doc, $infDps, 'dhEmi', $dhEmi);
        $this->addNode($doc, $infDps, 'verAplic', '1.10');
        $this->addNode($doc, $infDps, 'serie', $serie);
        $this->addNode($doc, $infDps, 'nDPS', $numero);
        $this->addNode($doc, $infDps, 'dCompet', substr($dhEmi, 0, 10));
        $this->addNode($doc, $infDps, 'tpEmit', '1');
        $this->addNode($doc, $infDps, 'cLocEmi', $cLocEmi);

        $prest = $doc->createElementNS(self::NS_NFSE, 'prest');
        $infDps->appendChild($prest);
        $this->addNode($doc, $prest, 'CNPJ', str_pad(preg_replace('/[^0-9]/', '', $dps->empresa->cnpj), 14, '0', STR_PAD_LEFT));

        $regTrib = $doc->createElementNS(self::NS_NFSE, 'regTrib');
        $prest->appendChild($regTrib);
        $this->addNode($doc, $regTrib, 'opSimpNac', '3');
        $this->addNode($doc, $regTrib, 'regApTribSN', '1');
        $this->addNode($doc, $regTrib, 'regEspTrib', '0');

        $toma = $doc->createElementNS(self::NS_NFSE, 'toma');
        $infDps->appendChild($toma);
        $docToma = preg_replace('/[^0-9]/', '', $dps->cliente->documento);
        $this->addNode($doc, $toma, (strlen($docToma) > 11 ? 'CNPJ' : 'CPF'), $docToma);
        $this->addNode($doc, $toma, 'xNome', $this->limpar($dps->cliente->nome));

        $end = $doc->createElementNS(self::NS_NFSE, 'end');
        $toma->appendChild($end);
        $en = $doc->createElementNS(self::NS_NFSE, 'endNac');
        $end->appendChild($en);
        $this->addNode($doc, $en, 'cMun', $dps->cliente->cidadeCodigoIbge);
        $this->addNode($doc, $en, 'CEP', preg_replace('/[^0-9]/', '', $dps->cliente->cep));
        $this->addNode($doc, $end, 'xLgr', $this->limpar($dps->cliente->logradouro));
        $this->addNode($doc, $end, 'nro', $dps->cliente->numero);
        $this->addNode($doc, $end, 'xBairro', $this->limpar($dps->cliente->bairro));

        $fone = $dps->cliente->telefone !== null && $dps->cliente->telefone !== ''
            ? preg_replace('/[^0-9]/', '', $dps->cliente->telefone)
            : null;
        $this->addNode($doc, $toma, 'fone', $fone);
        $this->addNode($doc, $toma, 'email', $dps->cliente->email);

        $serv = $doc->createElementNS(self::NS_NFSE, 'serv');
        $infDps->appendChild($serv);
        $loc = $doc->createElementNS(self::NS_NFSE, 'locPrest');
        $serv->appendChild($loc);
        $this->addNode($doc, $loc, 'cLocPrestacao', $cLocEmi);

        $cs = $doc->createElementNS(self::NS_NFSE, 'cServ');
        $serv->appendChild($cs);
        $this->addNode($doc, $cs, 'cTribNac', '080201');
        $this->addNode($doc, $cs, 'cTribMun', '001');
        $this->addNode($doc, $cs, 'xDescServ', $this->limpar($dps->servico->descricao));
        $this->addNode($doc, $cs, 'cIntContrib', '1');

        $ic = $doc->createElementNS(self::NS_NFSE, 'infoCompl');
        $this->addNode($doc, $ic, 'xInfComp', 'NOTA EMITIDA EM AMBIENTE DE HOMOLOGACAO');
        $serv->appendChild($ic);

        $val = $doc->createElementNS(self::NS_NFSE, 'valores');
        $infDps->appendChild($val);
        $vsp = $doc->createElementNS(self::NS_NFSE, 'vServPrest');
        $val->appendChild($vsp);
        $this->addNode($doc, $vsp, 'vServ', number_format($dps->servico->valorServicos, 2, '.', ''));

        $trib = $doc->createElementNS(self::NS_NFSE, 'trib');
        $val->appendChild($trib);
        $tm = $doc->createElementNS(self::NS_NFSE, 'tribMun');
        $trib->appendChild($tm);
        $this->addNode($doc, $tm, 'tribISSQN', '1');
        $this->addNode($doc, $tm, 'tpRetISSQN', '1');

        $tf = $doc->createElementNS(self::NS_NFSE, 'tribFed');
        $trib->appendChild($tf);
        $pf = $doc->createElementNS(self::NS_NFSE, 'piscofins');
        $tf->appendChild($pf);
        $this->addNode($doc, $pf, 'CST', '00');

        $tt = $doc->createElementNS(self::NS_NFSE, 'totTrib');
        $trib->appendChild($tt);
        $pt = $doc->createElementNS(self::NS_NFSE, 'pTotTrib');
        $tt->appendChild($pt);
        $this->addNode($doc, $pt, 'pTotTribFed', '0.00');
        $this->addNode($doc, $pt, 'pTotTribEst', '0.00');
        $this->addNode($doc, $pt, 'pTotTribMun', '0.00');

        return trim($doc->saveXML());
    }

    private function limpar($v) {
        $m = ['á'=>'A','à'=>'A','â'=>'A','ã'=>'A','ä'=>'A','é'=>'E','è'=>'E','ê'=>'E','ë'=>'E','í'=>'I','ì'=>'I','î'=>'I','ï'=>'I','ó'=>'O','ò'=>'O','ô'=>'O','õ'=>'O','ö'=>'O','ú'=>'U','ù'=>'U','û'=>'U','ü'=>'U','ç'=>'C'];
        return strtoupper(strtr($v, $m));
    }

    private function addNode($doc, $parent, $name, $value) {
        if ($value === null || $value === '') return;
        $el = $doc->createElementNS(self::NS_NFSE, $name);
        $el->appendChild($doc->createTextNode($value));
        $parent->appendChild($el);
    }
}

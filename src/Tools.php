<?php

namespace NFePHP\NFSePublica;

/**
 * Class for comunications with NFSe webserver in Nacional Standard
 *
 * @category  NFePHP
 * @package   NFePHP\NFSePublica
 * @copyright NFePHP Copyright (c) 2020
 * @license   http://www.gnu.org/licenses/lgpl.txt LGPLv3+
 * @license   https://opensource.org/licenses/MIT MIT
 * @license   http://www.gnu.org/licenses/gpl.txt GPLv3+
 * @author    Roberto L. Machado <linux.rlm at gmail dot com>
 * @link      http://github.com/nfephp-org/sped-nfse-publica for the canonical source repository
 */

use NFePHP\NFSePublica\Common\Tools as BaseTools;
use NFePHP\NFSePublica\RpsInterface;
use NFePHP\Common\Certificate;
use NFePHP\Common\Validator;
use NFePHP\NFSePublica\Common\Signer;

class Tools extends BaseTools
{
    const CANCEL_ERRO_EMISSAO = 1;
    const CANCEL_SERVICO_NAO_CONCLUIDO = 2;
    const CANCEL_DUPLICIDADE = 4;

    protected $xsdpath;
    protected $schema;

    /**
     * Constructor
     * @param string $config
     * @param Certificate $cert
     */
    public function __construct($config, Certificate $cert, $versionSchema = 'v03')
    {
        parent::__construct($config, $cert);
        $path = realpath(
            __DIR__ . '/../storage/schemes'
        );
        $this->schema =  "schema_nfse_$versionSchema.xsd";
        $this->xsdpath = $path . "/" . $this->schema;
    }

    private function getMensagens()
    {
        if (is_array($this->wsobj->msgns))
            return implode(" ", $this->wsobj->msgns);

        return $this->wsobj->msgns;
    }

    /**
     * Solicita o cancelamento de NFSe (SINCRONO)
     * @param integer $numero
     * @param integer $codigo
     * @param string $id
     * @param integer $numero_ano
     * @return string
     */
    public function cancelarNfse($numero, $codigo, $id = null, $numero_ano = null)
    {
        if (empty($id)) {
            $id = $numero;
        }

        if (empty($numero_ano)) {
            $numero_ano = date("Y");
        }
        $operation = 'CancelarNfse';
        $pedido = "<Pedido>"
            . "<InfPedidoCancelamento Id=\"{$id}\">"
            . "<IdentificacaoNfse>"
            . "<Numero>" . sprintf("%04d%011d", $numero_ano, $numero) . "</Numero>";

        if (!empty($this->config->cnpj)) {
            $pedido .= "<CpfCnpj><Cnpj>{$this->config->cnpj}</Cnpj></CpfCnpj>";
        } else {
            $pedido .= "<CpfCnpj><Cpf>{$this->config->cpf}</Cpf></CpfCnpj>";
        }
        $pedido .= "<InscricaoMunicipal>{$this->config->im}</InscricaoMunicipal>"
            . "<CodigoMunicipio>{$this->config->cmun}</CodigoMunicipio>"
            . "</IdentificacaoNfse>"
            . "<CodigoCancelamento>$codigo</CodigoCancelamento>"
            . "</InfPedidoCancelamento>"
            . "</Pedido>";
        $content = "<CancelarNfseEnvio {$this->getMensagens()}>"
            . $pedido
            . "</CancelarNfseEnvio>";

        $content = str_replace(
            ['<?xml version="1.0"?>', '<?xml version="1.0" encoding="UTF-8"?>'],
            '',
            $content
        );

        $content = Signer::sign(
            $this->certificate,
            $content,
            'InfPedidoCancelamento',
            'Id',
            OPENSSL_ALGO_SHA1,
            [true, false, null, null],
            'Pedido'
        );

        Validator::isValid($content, $this->xsdpath);
        return $this->send($content, $operation);
    }

    /**
     * Cancelamento com substituição por novo RPS
     * @param integer $numero_nfse_a_cancelar
     * @param integer $codigo 1-erro emissão 2-serviço não prestado 4-emissão em duplicidade
     * @param RpsInterface $novorps
     * @return string
     */
    public function substituirNfse(
        $numero_nfse_a_cancelar,
        RpsInterface $novorps,
        $codigo = self::CANCEL_ERRO_EMISSAO
    ) {
        $operation = "SubstituirNfse";
        $novorps->config($this->config);

        $pedido = "<Pedido>"
            . "<InfPedidoCancelamento id=\"cancel\">"
            . "<IdentificacaoNfse>"
            . "<Numero>" . sprintf("%015d", $numero_nfse_a_cancelar) . "</Numero>"
            . "<Cnpj>{$this->config->cnpj}</Cnpj>"
            . "<InscricaoMunicipal>{$this->config->im}</InscricaoMunicipal>"
            . "<CodigoMunicipio>{$this->config->cmun}</CodigoMunicipio>"
            . "</IdentificacaoNfse>"
            . "<CodigoCancelamento>{$codigo}</CodigoCancelamento>"
            . "</InfPedidoCancelamento>"
            . "</Pedido>";

        $content = "<SubstituirNfseEnvio xmlns=\"{$this->wsobj->msgns}\">"
            . "<SubstituicaoNfse id=\"subst\">"
            . $pedido
            . $novorps->render()
            . "</SubstituicaoNfse>"
            . "</SubstituirNfseEnvio>";

        $content = Signer::sign(
            $this->certificate,
            $content,
            'InfRps',
            'id',
            OPENSSL_ALGO_SHA1,
            [true, false, null, null],
            'Rps'
        );
        $content = Signer::sign(
            $this->certificate,
            $content,
            'InfPedidoCancelamento',
            'Id',
            OPENSSL_ALGO_SHA1,
            [true, false, null, null],
            'Pedido'
        );
        $content = Signer::sign(
            $this->certificate,
            $content,
            'SubstituicaoNfse',
            'Id',
            OPENSSL_ALGO_SHA1,
            [true, false, null, null],
            'SubstituirNfseEnvio'
        );
        $content = str_replace(
            ['<?xml version="1.0"?>', '<?xml version="1.0" encoding="UTF-8"?>'],
            '',
            $content
        );
        Validator::isValid($content, $this->xsdpath);
        return $this->send($content, $operation);
    }

    /**
     * Consulta Lote RPS (SINCRONO) após envio com recepcionarLoteRps() (ASSINCRONO)
     * complemento do processo de envio assincono.
     * Que deve ser usado quando temos mais de um RPS sendo enviado
     * por vez.
     * @param string $protocolo
     * @return string
     */
    public function consultarLoteRps($protocolo)
    {
        $operation = 'ConsultarLoteRps';
        $content = "<ConsultarLoteRpsEnvio {$this->getMensagens()}>"
            . $this->prestador
            . "</ConsultarLoteRpsEnvio>";

        $content = str_replace(
            ['</ConsultarLoteRpsEnvio>'],
            [
                "<Protocolo>{$protocolo}</Protocolo>"
                    . "</ConsultarLoteRpsEnvio>"
            ],
            $content
        );

        Validator::isValid($content, $this->xsdpath);

        return $this->send($content, $operation);
    }

    /**
     * Consulta NFSe emitidas por serviços prestados
     * @param \stdClass $params
     * @return string
     */
    public function consultarNfsePrestado($params)
    {
        $operation = 'ConsultarNfseServicoPrestado';
        if (empty($params->pagina)) {
            $params->pagina = 1;
        }
        $content = "<ConsultarNfseServicoPrestadoEnvio xmlns=\"{$this->wsobj->msgns}\">"
            . $this->prestador;
        if (!empty($params->numero)) {
            $content .= "<NumeroNfse>{$params->numero}</NumeroNfse>";
        }
        if (!empty($params->data_emissao_ini) && !empty($params->data_emissao_fim)) {
            $content .= "<PeriodoEmissao>"
                . "<DataInicial>{$params->data_emissao_ini}</DataInicial>"
                . "<DataFinal>{$params->data_emissao_fim}</DataFinal>"
                . "</PeriodoEmissao>";
        } else {
            if (!empty($params->competencia_ini) && !empty($params->competencia_fim)) {
                $content .= "<PeriodoCompetencia>"
                    . "<DataInicial>{$params->competencia_ini}</DataInicial>"
                    . "<DataFinal>{$params->competencia_fim}</DataFinal>"
                    . "</PeriodoCompetencia>";
            }
        }
        if (!empty($params->tomador)) {
            $content .= "<Tomador>"
                . "<CpfCnpj>";
            if (!empty($params->tomador->cnpj)) {
                $content .= "<Cnpj>{$params->tomador->cnpj}</Cnpj>";
            } else {
                $content .= "<Cpf>{$params->tomador->cpf}</Cpf>";
            }
            $content .= "</CpfCnpj>"
                . "<InscricaoMunicipal>{$params->tomador->im}</InscricaoMunicipal>"
                . "</Tomador>";
        }
        if (!empty($params->intermediario)) {
            $content .= "<Intermediario>"
                . "<CpfCnpj>";
            if (!empty($params->intermediario->cnpj)) {
                $content .= "<Cnpj>{$params->intermediario->cnpj}</Cnpj>";
            } else {
                $content .= "<Cpf>{$params->intermediario->cpf}</Cpf>";
            }
            $content .= "</CpfCnpj>"
                . "<InscricaoMunicipal>{$params->intermediario->im}</InscricaoMunicipal>"
                . "</Intermediario>";
        }
        $content .= "<Pagina>{$params->pagina}</Pagina>"
            . "</ConsultarNfseServicoPrestadoEnvio>";
        Validator::isValid($content, $this->xsdpath);
        return $this->send($content, $operation);
    }

    /**
     * Consulta dos serviços tomados
     * @param \stdClass $params
     * @return string
     */
    public function consultarNfseTomado($params)
    {
        $operation = 'ConsultarNfseServicoTomado';
        if (empty($params->pagina)) {
            $params->pagina = 1;
        }
        $content  = "<ConsultarNfseServicoTomadoEnvio xmlns=\"{$this->wsobj->msgns}\">"
            . "<Consulente>"
            . "<CpfCnpj>";
        if (!empty($this->config->cnpj)) {
            $content .= "<Cnpj>{$this->config->cnpj}</Cnpj>";
        } else {
            $content .= "<Cpf>{$this->config->cpf}</Cpf>";
        }
        $content .= "</CpfCnpj>"
            . "<InscricaoMunicipal>{$this->config->im}</InscricaoMunicipal>"
            . "</Consulente>";
        if (!empty($params->numero)) {
            $content .= "<NumeroNfse>{$params->numero}</NumeroNfse>";
        }
        if (!empty($params->data_emissao_ini) && !empty($params->data_emissao_fim)) {
            $content .= "<PeriodoEmissao>"
                . "<DataInicial>{$params->data_emissao_ini}</DataInicial>"
                . "<DataFinal>{$params->data_emissao_fim}</DataFinal>"
                . "</PeriodoEmissao>";
        } else {
            if (!empty($params->competencia_ini) && !empty($params->competencia_fim)) {
                $content .= "<PeriodoCompetencia>"
                    . "<DataInicial>{$params->competencia_ini}</DataInicial>"
                    . "<DataFinal>{$params->competencia_fim}</DataFinal>"
                    . "</PeriodoCompetencia>";
            }
        }
        if (!empty($params->prestador)) {
            $content .= "<Prestador>"
                . "<CpfCnpj>";
            if (!empty($params->prestador->cnpj)) {
                $content .= "<Cnpj>{$params->prestador->cnpj}</Cnpj>";
            } else {
                $content .= "<Cpf>{$params->prestador->cpf}</Cpf>";
            }
            $content .= "</CpfCnpj>"
                . "<InscricaoMunicipal>{$params->prestador->im}</InscricaoMunicipal>"
                . "</Prestador>";
        }
        if (!empty($params->intermediario)) {
            $content .= "<Intermediario>"
                . "<CpfCnpj>";
            if (!empty($params->intermediario->cnpj)) {
                $content .= "<Cnpj>{$params->intermediario->cnpj}</Cnpj>";
            } else {
                $content .= "<Cpf>{$params->intermediario->cpf}</Cpf>";
            }
            $content .= "</CpfCnpj>"
                . "<InscricaoMunicipal>{$params->intermediario->im}</InscricaoMunicipal>"
                . "</Intermediario>";
        }
        $content .= "<Pagina>{$params->pagina}</Pagina>"
            . "</ConsultarNfseServicoTomadoEnvio>";
        Validator::isValid($content, $this->xsdpath);
        return $this->send($content, $operation);
    }

    /**
     * Consulta NFSe emitidas por faixa de numeros (SINCRONO)
     * @param integer $numero_ini
     * @param integer $numero_fim
     * @param integer $numero_ano
     * @return string
     */
    public function consultarNfseFaixa($numero_ini, $numero_fim, $numero_ano, $pagina = 1)
    {
        $operation = 'ConsultarNfsePorFaixa';

        $content = "<ConsultarNfseFaixaEnvio {$this->getMensagens()}>"
            . $this->prestador
            . "</ConsultarNfseFaixaEnvio>";

        $content = str_replace(
            ['</ConsultarNfseFaixaEnvio>'],
            [
                "<Faixa>"
                    . "<NumeroNfseInicial>" . sprintf("%04d%011d", $numero_ano, $numero_ini) . "</NumeroNfseInicial>"
                    . "<NumeroNfseFinal>" . sprintf("%04d%011d", $numero_ano, $numero_fim) . "</NumeroNfseFinal>"
                    . "</Faixa>"
                    . "<Pagina>{$pagina}</Pagina>"
                    . "</ConsultarNfseFaixaEnvio>"
            ],
            $content
        );

        Validator::isValid($content, $this->xsdpath);
        return $this->send($content, $operation);
    }

    /**
     * Consulta NFSe por RPS (SINCRONO)
     * @param integer $numero
     * @param string $serie
     * @param integer $tipo
     * @return string
     */
    public function consultarNfseRps($numero, $serie, $tipo)
    {
        $operation = "ConsultarNfsePorRps";
        $content = "<ConsultarNfseRpsEnvio {$this->getMensagens()}>"
            . "<IdentificacaoRps>"
            . "<Numero>{$numero}</Numero>"
            . "<Serie>{$serie}</Serie>"
            . "<Tipo>{$tipo}</Tipo>"
            . "</IdentificacaoRps>"
            . $this->prestador
            . "</ConsultarNfseRpsEnvio>";

        Validator::isValid($content, $this->xsdpath);
        return $this->send($content, $operation);
    }

    protected function mountRpsXML($arps, $mode = 'sincrono', $limit = 500)
    {
        if (count($arps) > $limit)
            throw new \Exception("O limite é de $limit RPS por lote enviado em modo $mode.");

        $content = '';
        foreach ($arps as $rps)
            $content .= "<Rps>$rps</Rps>";

        return $content;
    }

    protected function mountLoteRpsXML($rootElement, $arps, $lote, $method)
    {
        $content = $this->mountRpsXML($arps, $method);
        $qtdRps = count($arps);

        $contentmsg = "<$rootElement {$this->getMensagens()}>"
            . "<LoteRps Id=\"loteRPS_$lote\" versao=\"{$this->wsobj->version}\">"
            . "<NumeroLote>$lote</NumeroLote>"
            . "<CpfCnpj><Cnpj>{$this->config->cnpj}</Cnpj></CpfCnpj>"
            . "<InscricaoMunicipal>" . $this->config->im . "</InscricaoMunicipal>"
            . "<QuantidadeRps>$qtdRps</QuantidadeRps>"
            . "<ListaRps>"
            . $content
            . "</ListaRps>"
            . "</LoteRps>"
            . "</$rootElement>";

        $contentmsg = $this->removeTag(
            $contentmsg,
            ['<?xml version="1.0"?>', '<?xml version="1.0" encoding="UTF-8"?>']
        );

        $contentmsg = Signer::sign(
            $this->certificate,
            $contentmsg,
            'InfDeclaracaoPrestacaoServico',
            'Id',
            OPENSSL_ALGO_SHA1,
            [true, false, null, null],
            'Rps'
        );

        $content = Signer::sign(
            $this->certificate,
            $contentmsg,
            'LoteRps',
            'Id',
            OPENSSL_ALGO_SHA1,
            [true, false, null, null],
            $rootElement
        );

        return $content;
    }

    /**
     * Envia LOTE de RPS para emissão de NFSe (SINCRONO)
     * @param array $arps Array contendo de 1 a 2 RPS::class
     * @param string $lote Número do lote de envio
     * @return string
     * @throws \Exception
     */
    public function recepcionarRps($arps, $lote)
    {
        $operation = 'RecepcionarLoteRpsSincrono';

        $content = $this->mountLoteRpsXML(
            'EnviarLoteRpsSincronoEnvio',
            $arps,
            $lote,
            'sincrono'
        );

        Validator::isValid($content, $this->xsdpath);

        return $this->send($content, $operation);
    }

    protected function removeTag($content, $tag)
    {
        return str_replace(
            $tag,
            '',
            $content
        );
    }

    /**
     * Envia LOTE de RPS para emissão de NFSe (ASSINCRONO)
     * @param array $arps Array contendo de 1 a 2 RPS::class
     * @param string $lote Número do lote de envio
     * @return string
     * @throws \Exception
     */
    public function recepcionarLoteRps($arps, $lote)
    {
        $operation = 'RecepcionarLoteRps';

        $content = $this->mountLoteRpsXML(
            'EnviarLoteRpsEnvio',
            $arps,
            $lote,
            'assincrono'
        );


        Validator::isValid($content, $this->xsdpath);

        return $this->send($content, $operation);
    }

    /**
     * Assina RPS e retorna a string da requisição renderizada com a assinatura
     * @param RpsInterface $rps
     */
    public function gerarNfseSignedRequest(RpsInterface $rps)
    {
        $rps->config($this->config);
        $content = "<GerarNfseEnvio xmlns=\"{$this->wsobj->msgns}\">"
            . $rps->render()
            . "</GerarNfseEnvio>";
        $content = Signer::sign(
            $this->certificate,
            $content,
            'InfRps',
            'id',
            OPENSSL_ALGO_SHA1,
            [true, false, null, null],
            'Rps'
        );
        $content = str_replace(
            ['<?xml version="1.0"?>', '<?xml version="1.0" encoding="UTF-8"?>'],
            '',
            $content
        );

        return $content;
    }

    /**
     * Solicita a emissão de uma NFSe a partir de uma string
     * com a requisição 'gerarNfse' assinado de forma SINCRONA
     * @param string $content
     * @return string
     */
    public function gerarNfseFromString(string $content)
    {
        $operation = "GerarNfse";
        Validator::isValid($content, $this->xsdpath);
        return $this->send($content, $operation);
    }

public function gerarNfse($content)
    {
        $operation = "GerarNfse";

        $ini = strpos($content, "<Rps>");
        $fim = strpos($content, "</Rps>") + 6;
        $tagRps = substr($content, $ini, $fim - $ini);

        $newContent = substr($content, 0, $ini) . substr($content, $fim);
        $newContent = str_replace("<InfDeclaracaoPrestacaoServico", "<Rps><InfDeclaracaoPrestacaoServico", $newContent);
        $newContent = str_replace("</InfDeclaracaoPrestacaoServico>", "</InfDeclaracaoPrestacaoServico></Rps>", $newContent);
        $newContent = substr($newContent, 39);
        $newContent = str_replace("<Competencia>", "{$tagRps}<Competencia>", $newContent);
        

        $content = "<GerarNfseEnvio {$this->getMensagens()}>"
            . $newContent
            . "</GerarNfseEnvio>";

        $content = Signer::sign(
            $this->certificate,
            $content,
            'InfDeclaracaoPrestacaoServico',
            'Id',
            OPENSSL_ALGO_SHA1,
            [true, false, null, null],
            'Rps'
        );
        Validator::isValid($content, $this->xsdpath);
        
        return $this->send($content, $operation);
    }
        /**
     * Solicita a emissão de uma NFSe de forma SINCRONA
     * @param RpsInterface $rps
     * @return string
     */
    public function gerarNfse1(RpsInterface $rps)
    {
        return $this->gerarNfseFromString($this->gerarNfseSignedRequest($rps));
    }

    /**
     * Enviar Carta de Correção
     */
    public function cartaCorrecao($params)
    {
        $operation = "CartaCorrecaoNfseEnvio";

        $content = "<CartaCorrecaoNfseEnvio xmlns=\"{$this->wsobj->msgns}\"> "
            . "<Pedido>"
            . "<InfPedidoCartaCorrecao id='assinar'>"
            . "<IdentificacaoNfse>"
            . "<Numero>$params->numero</Numero>"
            . "<Cnpj>$params->cnpj</Cnpj>"
            . "<CodigoMunicipio>$params->cmun</CodigoMunicipio>"
            . "</IdentificacaoNfse>"
            . "<TomadorServico>"
            . "<IdentificacaoTomador>"
            . "<CpfCnpj>"
            . "<Cnpj>{$params->tomador->cnpj}</Cnpj>"
            . "</CpfCnpj>"
            . "</IdentificacaoTomador>"
            . "<RazaoSocial>{$params->tomador->razao}</RazaoSocial>"
            . "<Endereco>"
            . "<Endereco>{$params->tomador->endereco}</Endereco>"
            . "<Numero>{$params->tomador->numero}</Numero>"
            . "<Bairro>{$params->tomador->bairro}</Bairro>"
            . "<Cep>{$params->tomador->cep}</Cep>"
            . "</Endereco>"
            . "<Contato>"
            . "<Email>{$params->tomador->email}</Email>"
            . "</Contato>"
            . "</TomadorServico>"
            . "<Discriminacao>{$params->motivo}</Discriminacao>"
            . "</InfPedidoCartaCorrecao>"
            . "</Pedido>"
            . "</CartaCorrecaoNfseEnvio>";

        $content = Signer::sign(
            $this->certificate,
            $content,
            'InfPedidoCartaCorrecao',
            'id',
            OPENSSL_ALGO_SHA1,
            [true, false, null, null],
            'Pedido'
        );

        Validator::isValid($content, $this->xsdpath);
        return $this->send($content, $operation);
    }

    /**
     * ConsultarSituacaoLoteRpsEnvio
     */
    public function consultarSituacaoLoteRps($params)
    {
        $operation = "ConsultarSituacaoLoteRps";

        $content = "<ConsultarSituacaoLoteRpsEnvio xmlns=\"{$this->wsobj->msgns}\"> "
            . "<Prestador id='assinar'>"
            . "<Cnpj>$params->cnpj</Cnpj>"
            . "<InscricaoMunicipal>$params->im</InscricaoMunicipal>"
            . "</Prestador>"
            . "</ConsultarSituacaoLoteRpsEnvio>";

        $content = Signer::sign(
            $this->certificate,
            $content,
            'Prestador',
            'id',
            OPENSSL_ALGO_SHA1,
            [true, false, null, null],
        );

        $content = str_replace(
            ['</ConsultarSituacaoLoteRpsEnvio>'],
            [
                "<Protocolo>{$params->protocolo}</Protocolo>"
                    . "</ConsultarSituacaoLoteRpsEnvio>"
            ],
            $content
        );

        Validator::isValid($content, $this->xsdpath);
        return $this->send($content, $operation);
    }
}

<?php

namespace NFePHP\NFSePublica\ElotechOxy;

/**
 * Comunicação com o webservice NFS-e Elotech (OXY ISS) - ABRASF 2.03
 * namespace http://shad.elotech.com.br/schemas/iss/nfse_v2_03.xsd
 *
 * Diferenças em relação ao ABRASF padrão (NFePHP\NFSePublica\Abrasf\Tools):
 *  - SOAP document/literal: a mensagem vai direto no Body (sem nfseCabecMsg/nfseDadosMsg) e SOAPAction vazio;
 *  - autenticação pela tag IdentificacaoRequerente (CNPJ, IM, Senha do webservice e Homologa);
 *  - o XML não é assinado (o schema não possui ds:Signature nem atributo Id);
 *  - homologação usa a mesma URL de produção com <Homologa>true</Homologa>;
 *  - não existe a operação GerarNfse: a emissão síncrona é pelo EnviarLoteRpsSincrono.
 */

use NFePHP\Common\Certificate;
use NFePHP\Common\Validator;
use NFePHP\NFSePublica\Common\Soap\SoapCurl;
use NFePHP\NFSePublica\Common\Soap\SoapInterface;

class Tools
{
    const NAMESPACE_NFSE = 'http://shad.elotech.com.br/schemas/iss/nfse_v2_03.xsd';
    const NAMESPACE_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';
    const VERSAO = '2.03';

    const CANCEL_ERRO_EMISSAO = 1;
    const CANCEL_SERVICO_NAO_PRESTADO = 2;
    const CANCEL_DUPLICIDADE = 4;

    public $lastRequest;
    public $lastResponse;

    protected $config;
    protected $certificate;
    protected $wsobj;
    protected $soap;
    protected $xsdpath;
    protected $requerente;

    /**
     * @param string $config json com cnpj (ou cpf), im, cmun, tpamb (1 produção, 2 homologação) e senha
     * @param Certificate $cert
     */
    public function __construct($config, Certificate $cert)
    {
        $this->config = json_decode($config);
        $this->certificate = $cert;

        if (empty($this->config->senha))
            throw new \Exception("Senha do webservice NFS-e não informada.");

        $this->wsobj = $this->loadWsobj($this->config->cmun);
        $this->xsdpath = dirname(__DIR__, 2) . '/storage/schemes/schema_nfse_v2_03.xsd';
        $this->buildRequerenteTag();
    }

    public function loadSoapClass(SoapInterface $soap)
    {
        $this->soap = $soap;
    }

    public function isHomologacao(): bool
    {
        return intval($this->config->tpamb) !== 1;
    }

    /**
     * Envia lote de RPS e já retorna as NFS-e geradas (SINCRONO)
     * @param array $arps lista de XML <InfDeclaracaoPrestacaoServico>
     * @param int $lote
     */
    public function enviarLoteRpsSincrono(array $arps, $lote)
    {
        return $this->send($this->mountLoteRps('EnviarLoteRpsSincronoEnvio', $arps, $lote));
    }

    /**
     * Envia lote de RPS (ASSINCRONO). O retorno traz NumeroLote e DataRecebimento;
     * as NFS-e são obtidas depois com consultarLoteRps().
     * @param array $arps lista de XML <InfDeclaracaoPrestacaoServico>
     * @param int $lote
     */
    public function enviarLoteRps(array $arps, $lote)
    {
        return $this->send($this->mountLoteRps('EnviarLoteRpsEnvio', $arps, $lote));
    }

    /**
     * Consulta lote enviado. Na Elotech a consulta é pelo número do lote (não há protocolo).
     */
    public function consultarLoteRps($numeroLote)
    {
        $content = "<ConsultarLoteRpsEnvio xmlns=\"" . self::NAMESPACE_NFSE . "\">"
            . $this->requerente
            . "<NumeroLote>{$numeroLote}</NumeroLote>"
            . "</ConsultarLoteRpsEnvio>";

        return $this->send($content);
    }

    public function consultarNfseRps($numero, $serie, $tipo = 1)
    {
        $content = "<ConsultarNfseRpsEnvio xmlns=\"" . self::NAMESPACE_NFSE . "\">"
            . "<IdentificacaoRps>"
            . "<Numero>{$numero}</Numero>"
            . "<Serie>{$serie}</Serie>"
            . "<Tipo>{$tipo}</Tipo>"
            . "</IdentificacaoRps>"
            . $this->requerente
            . "</ConsultarNfseRpsEnvio>";

        return $this->send($content);
    }

    public function consultarNfseFaixa($numeroInicial, $numeroFinal = null, $pagina = 1)
    {
        $content = "<ConsultarNfseFaixaEnvio xmlns=\"" . self::NAMESPACE_NFSE . "\">"
            . $this->requerente
            . "<Faixa>"
            . "<NumeroNfseInicial>{$numeroInicial}</NumeroNfseInicial>"
            . (empty($numeroFinal) ? '' : "<NumeroNfseFinal>{$numeroFinal}</NumeroNfseFinal>")
            . "</Faixa>"
            . "<Pagina>{$pagina}</Pagina>"
            . "</ConsultarNfseFaixaEnvio>";

        return $this->send($content);
    }

    /**
     * @param int $numero número da NFS-e
     * @param string $chaveAcesso ChaveAcesso retornada na emissão
     * @param int $codigo 1 erro na emissão | 2 serviço não prestado | 4 duplicidade
     */
    public function cancelarNfse($numero, $chaveAcesso, $codigo = self::CANCEL_ERRO_EMISSAO)
    {
        $content = "<CancelarNfseEnvio xmlns=\"" . self::NAMESPACE_NFSE . "\">"
            . $this->requerente
            . "<Pedido>"
            . "<InfPedidoCancelamento>"
            . "<IdentificacaoNfse>"
            . "<Numero>{$numero}</Numero>"
            . $this->cpfCnpjTag()
            . "<InscricaoMunicipal>{$this->config->im}</InscricaoMunicipal>"
            . "<CodigoMunicipio>{$this->config->cmun}</CodigoMunicipio>"
            . "</IdentificacaoNfse>"
            . "<ChaveAcesso>{$chaveAcesso}</ChaveAcesso>"
            . "<CodigoCancelamento>{$codigo}</CodigoCancelamento>"
            . "</InfPedidoCancelamento>"
            . "</Pedido>"
            . "</CancelarNfseEnvio>";

        return $this->send($content);
    }

    /**
     * Monta (sem enviar) a mensagem do lote. Útil para conferência/validação local.
     * @param string $rootElement EnviarLoteRpsSincronoEnvio | EnviarLoteRpsEnvio
     */
    public function mountLoteRps($rootElement, array $arps, $lote)
    {
        if (count($arps) == 0)
            throw new \Exception("Nenhum RPS informado para o lote.");

        $rpsList = '';
        foreach ($arps as $rps)
            $rpsList .= "<Rps>" . $this->removeXmlDeclaration($rps) . "</Rps>";

        return "<{$rootElement} xmlns=\"" . self::NAMESPACE_NFSE . "\">"
            . $this->requerente
            . "<LoteRps versao=\"" . self::VERSAO . "\">"
            . "<NumeroLote>{$lote}</NumeroLote>"
            . $this->cpfCnpjTag()
            . "<InscricaoMunicipal>{$this->config->im}</InscricaoMunicipal>"
            . "<QuantidadeRps>" . count($arps) . "</QuantidadeRps>"
            . "<ListaRps>{$rpsList}</ListaRps>"
            . "</LoteRps>"
            . "</{$rootElement}>";
    }

    /**
     * Valida a mensagem contra o XSD Elotech. Lança exceção com os erros encontrados.
     */
    public function validate($content)
    {
        return Validator::isValid($content, $this->xsdpath);
    }

    /**
     * Valida, envia e retorna o conteúdo do Body da resposta, sem namespaces.
     */
    protected function send($content)
    {
        $content = $this->removeXmlDeclaration($content);
        $this->validate($content);

        $url = $this->wsobj->producao;
        if ($this->isHomologacao() && !empty($this->wsobj->homologacao))
            $url = $this->wsobj->homologacao;

        if (empty($url))
            throw new \Exception("Não está registrada a URL do webservice desse municipio.");

        $request = "<soapenv:Envelope xmlns:soapenv=\"" . self::NAMESPACE_SOAP . "\">"
            . "<soapenv:Header/>"
            . "<soapenv:Body>{$content}</soapenv:Body>"
            . "</soapenv:Envelope>";
        $this->lastRequest = $request;

        if (empty($this->soap))
            $this->soap = new SoapCurl($this->certificate);

        $parameters = [
            "Content-Type: text/xml;charset=UTF-8",
            "SOAPAction: \"\"",
            "Content-length: " . strlen($request)
        ];

        $operation = substr($content, 1, strcspn($content, " >", 1));
        $response = (string) $this->soap->send($operation, $url, '', $request, $parameters);
        $this->lastResponse = $response;

        return $this->extractContentFromResponse($response);
    }

    /**
     * Retorna o primeiro elemento do Body sem prefixos/namespaces (ns2:...),
     * para que possa ser lido com simplexml_load_string() diretamente.
     */
    protected function extractContentFromResponse($response)
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (!@$dom->loadXML($response))
            throw new \Exception("Resposta inválida do webservice: " . substr($response, 0, 500));

        $body = $dom->getElementsByTagNameNS(self::NAMESPACE_SOAP, 'Body')->item(0);
        if (!$body)
            return $this->removeNamespaces($response);

        foreach ($body->childNodes as $node) {
            if ($node->nodeType !== XML_ELEMENT_NODE)
                continue;

            if ($node->localName === 'Fault') {
                $fault = $node->getElementsByTagName('faultstring')->item(0);
                throw new \Exception("SOAP Fault: " . ($fault ? $fault->textContent : $dom->saveXML($node)));
            }

            return $this->removeNamespaces($dom->saveXML($node));
        }

        throw new \Exception("Resposta do webservice sem conteúdo.");
    }

    protected function removeNamespaces($xml)
    {
        $xml = preg_replace('/\sxmlns(:[\w\-]+)?="[^"]*"/', '', $xml);
        return preg_replace('/(<\/?)[\w\-]+:/', '$1', $xml);
    }

    protected function removeXmlDeclaration($xml)
    {
        return trim(preg_replace('/<\?xml[^>]*\?>/', '', $xml));
    }

    protected function buildRequerenteTag()
    {
        $senha = htmlspecialchars($this->config->senha, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $homologa = $this->isHomologacao() ? 'true' : 'false';

        $this->requerente = "<IdentificacaoRequerente>"
            . $this->cpfCnpjTag()
            . "<InscricaoMunicipal>{$this->config->im}</InscricaoMunicipal>"
            . "<Senha>{$senha}</Senha>"
            . "<Homologa>{$homologa}</Homologa>"
            . "</IdentificacaoRequerente>";
    }

    protected function cpfCnpjTag()
    {
        return !empty($this->config->cnpj)
            ? "<CpfCnpj><Cnpj>{$this->config->cnpj}</Cnpj></CpfCnpj>"
            : "<CpfCnpj><Cpf>{$this->config->cpf}</Cpf></CpfCnpj>";
    }

    protected function loadWsobj($cmun)
    {
        $path = dirname(__DIR__, 2) . '/storage/urls_webservices.json';
        $urls = json_decode(file_get_contents($path), true);
        if (empty($urls[$cmun]))
            throw new \Exception("Não localizado parâmetros para esse municipio.");

        return (object) $urls[$cmun];
    }
}

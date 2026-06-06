<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;

final class PemXmlSigner
{
    public function __construct(
        private readonly string $certificatePem,
        private readonly string $privateKeyPem,
    ) {}

    public function sign(string $xml, string $tagName, string $idAttribute = 'Id'): string
    {
        if ($xml === '') {
            throw new RuntimeException('Conteúdo XML vazio.');
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $dom->loadXML($xml);

        $root = $dom->documentElement;
        $node = $dom->getElementsByTagName($tagName)->item(0);
        if (!$root instanceof DOMElement || !$node instanceof DOMElement) {
            throw new RuntimeException("Tag {$tagName} não encontrada para assinatura.");
        }

        $this->createSignature($dom, $node, $idAttribute);

        return str_replace(["\n", "\r", "\t"], '', (string)$dom->saveXML());
    }

    private function createSignature(DOMDocument $dom, DOMElement $node, string $idAttribute): void
    {
        $id = $node->getAttribute($idAttribute);
        if ($id === '') {
            throw new RuntimeException("Tag a ser assinada deve possuir o atributo '{$idAttribute}'.");
        }

        $nsDsig = 'http://www.w3.org/2000/09/xmldsig#';
        $signatureNode = $dom->createElementNS($nsDsig, 'Signature');
        $node->parentNode?->appendChild($signatureNode);

        $signedInfo = $dom->createElement('SignedInfo');
        $signatureNode->appendChild($signedInfo);

        $canonicalizationMethod = $dom->createElement('CanonicalizationMethod');
        $canonicalizationMethod->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $signedInfo->appendChild($canonicalizationMethod);

        $signatureMethod = $dom->createElement('SignatureMethod');
        $signatureMethod->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#rsa-sha1');
        $signedInfo->appendChild($signatureMethod);

        $reference = $dom->createElement('Reference');
        $reference->setAttribute('URI', "#{$id}");
        $signedInfo->appendChild($reference);

        $transforms = $dom->createElement('Transforms');
        $reference->appendChild($transforms);

        $transformEnvelope = $dom->createElement('Transform');
        $transformEnvelope->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $transforms->appendChild($transformEnvelope);

        $transformCanonical = $dom->createElement('Transform');
        $transformCanonical->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $transforms->appendChild($transformCanonical);

        $digestMethod = $dom->createElement('DigestMethod');
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#sha1');
        $reference->appendChild($digestMethod);

        $digestValue = $dom->createElement('DigestValue', $this->makeDigest($node));
        $reference->appendChild($digestValue);

        $signatureValue = $dom->createElement('SignatureValue', $this->signCanonicalized($signedInfo));
        $signatureNode->appendChild($signatureValue);

        $keyInfo = $dom->createElement('KeyInfo');
        $signatureNode->appendChild($keyInfo);

        $x509Data = $dom->createElement('X509Data');
        $keyInfo->appendChild($x509Data);

        $x509Certificate = $dom->createElement('X509Certificate', $this->cleanCertificate());
        $x509Data->appendChild($x509Certificate);
    }

    private function makeDigest(DOMNode $node): string
    {
        return base64_encode(hash('sha1', $node->C14N(true, false), true));
    }

    private function signCanonicalized(DOMNode $signedInfo): string
    {
        $signature = '';
        $ok = openssl_sign((string)$signedInfo->C14N(true, false), $signature, $this->privateKeyPem, OPENSSL_ALGO_SHA1);
        if (!$ok) {
            throw new RuntimeException('Falha ao assinar o XML.');
        }

        return base64_encode($signature);
    }

    private function cleanCertificate(): string
    {
        $cert = str_replace('-----BEGIN CERTIFICATE-----', '', $this->certificatePem);
        $cert = str_replace('-----END CERTIFICATE-----', '', $cert);
        return str_replace(["\r", "\n"], '', $cert);
    }
}

<?php

class AccessContextService
{
    private array $request;
    private array $session;

    public function __construct(array $request = [], array $session = [])
    {
        $this->request = !empty($request) ? $request : $_REQUEST;
        $this->session = !empty($session) ? $session : $_SESSION;
    }

    /**
     * @return array{context:string,is_embedded:bool}
     */
    public function getAccessContext(): array
    {
        $isEmbedded = $this->isEmbeddedRequest();
        $context = $isEmbedded ? 'embedded' : 'direct';

        if ($this->isAccessContextUnknown()) {
            $context = 'unknown';
            $isEmbedded = false;
        }

        return [
            'context' => $context,
            'is_embedded' => $isEmbedded,
        ];
    }

    /**
     * @param array<string, mixed> $accessData
     * @return array{context:string,is_embedded:bool}
     */
    public function resolveAccessContext(array $accessData = []): array
    {
        $context = isset($accessData['context']) ? (string) $accessData['context'] : '';
        $isEmbedded = $accessData['is_embedded'] ?? null;

        if ($context === '' || !is_bool($isEmbedded)) {
            return $this->getAccessContext();
        }

        return [
            'context' => $context,
            'is_embedded' => $isEmbedded,
        ];
    }

    /**
     * @return array{domain:string,auth_id:string}
     */
    public function getAuthContext(): array
    {
        $authId = '';
        if (!empty($this->request['AUTH_ID'])) {
            $authId = (string) $this->request['AUTH_ID'];
        } elseif (!empty($this->request['access_token'])) {
            $authId = (string) $this->request['access_token'];
        } elseif (!empty($this->request['auth']) && is_string($this->request['auth'])) {
            $authId = (string) $this->request['auth'];
        } elseif (!empty($this->request['auth']) && is_array($this->request['auth']) && !empty($this->request['auth']['access_token'])) {
            $authId = (string) $this->request['auth']['access_token'];
        } elseif (!empty($this->session['AUTH_ID'])) {
            $authId = (string) $this->session['AUTH_ID'];
        }

        $domain = '';
        if (!empty($this->request['DOMAIN'])) {
            $domain = (string) $this->request['DOMAIN'];
        } elseif (!empty($this->request['domain'])) {
            $domain = (string) $this->request['domain'];
        } elseif (!empty($this->session['DOMAIN'])) {
            $domain = (string) $this->session['DOMAIN'];
        }

        $domain = strtolower(trim($domain));
        $authId = trim($authId);

        if ($domain === '' || $authId === '') {
            return [
                'domain' => '',
                'auth_id' => '',
            ];
        }

        if (preg_match('/[^a-z0-9\.\-]/i', $domain) === 1) {
            return [
                'domain' => '',
                'auth_id' => '',
            ];
        }

        return [
            'domain' => $domain,
            'auth_id' => $authId,
        ];
    }

    private function isEmbeddedRequest(): bool
    {
        if (!empty($this->request['PLACEMENT'])) {
            return true;
        }

        if (!empty($this->request['PLACEMENT_OPTIONS'])) {
            return true;
        }

        if (!empty($this->request['IFRAME'])) {
            return true;
        }

        if (!empty($this->request['B24_FRAME'])) {
            return true;
        }

        if (!empty($this->session['PLACEMENT'])) {
            return true;
        }

        if (!empty($this->session['PLACEMENT_OPTIONS'])) {
            return true;
        }

        if (!empty($this->session['IFRAME'])) {
            return true;
        }

        if (!empty($this->session['B24_FRAME'])) {
            return true;
        }

        return false;
    }

    private function isAccessContextUnknown(): bool
    {
        if ($this->isEmbeddedRequest()) {
            return false;
        }

        if (!empty($this->request)) {
            return false;
        }

        if (!empty($this->session['PLACEMENT']) || !empty($this->session['IFRAME']) || !empty($this->session['B24_FRAME'])) {
            return false;
        }

        return true;
    }
}

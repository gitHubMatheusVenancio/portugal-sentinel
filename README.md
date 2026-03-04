# 🛡️ Portugal Sentinel

> **Monitor de Risco Nacional em tempo real, alimentado por IA (Groq · Llama 3.3 70B)**

Portugal Sentinel é um dashboard geopolítico que analisa automaticamente o nível de risco de Portugal em 9 vectores estratégicos — de ameaças militares a segurança alimentar — utilizando inteligência artificial generativa. Actualiza-se via ciclo cron de 30 minutos e apresenta recomendações de segurança dinâmicas conforme o nível de alerta.

---

## 📸 Preview

![Portugal Sentinel Screenshot](portugal-sentinel-screenshot.png)

---

## ✨ Funcionalidades

- **Análise por IA** — cada actualização chama o modelo Llama 3.3 70B via Groq API, que avalia a situação actual e gera pontuações e textos explicativos
- **9 vectores de risco** — Geopolítica, Ameaça Militar, Economia, Energia, Ordem Social, Ciber-Segurança, Saúde Pública, Ambiente e Segurança Alimentar
- **Escala cromática de 6 níveis** — de Verde (mínimo) a Magenta (emergência)
- **Recomendações dinâmicas** — geradas conforme o nível de risco detectado, em 6 categorias de preparação
- **Cron automático de 30 minutos** — o dashboard actualiza-se sem intervenção manual
- **Contador de visitantes persistente** — online / hoje / total, gravado em `contador.txt`
- **Modo dual** — chama a Groq directamente (teste/Claude.ai) ou via proxy PHP (produção)
- **Design terminal/militar** — aesthetics de ecrã táctico com scanlines, grid e animações CSS

---

## 🗂️ Estrutura de Ficheiros

```
sentinel/
├── portugal-risk-dashboard.html   # Frontend — dashboard completo (single file)
├── groq-proxy.php                 # Proxy PHP — esconde a API key no servidor
├── contador.php                   # API de contagem de visitantes
├── contador.txt                   # Dados persistentes do contador (auto-gerado)
└── README.md                      # Este ficheiro
```

---

## 🚀 Instalação no Hostinger

### 1. Obter API Key Groq (gratuita)

1. Aceda a [console.groq.com](https://console.groq.com)
2. Crie uma conta gratuita
3. Vá a **API Keys → Create API Key**
4. Copie a chave `gsk_...`

### 2. Configurar o proxy

Edite `groq-proxy.php` e substitua:

```php
define('GROQ_API_KEY', 'gsk_COLOQUE_AQUI_A_SUA_KEY');
```

Atualize também os domínios autorizados:

```php
$allowed_origins = [
    'https://seudominio.com',
    'https://www.seudominio.com',
];
```

### 3. Upload para o servidor

Carregue os ficheiros para uma pasta no Hostinger, por exemplo:

```
public_html/sentinel/
```

O ficheiro `contador.txt` será criado automaticamente na primeira visita. Certifique-se que a pasta tem permissões de escrita (`chmod 755` ou `775`).

### 4. Aceder

```
https://seudominio.com/sentinel/portugal-risk-dashboard.html
```

---

## 🧪 Teste Local / Claude.ai

Para testar sem servidor PHP, abra `portugal-risk-dashboard.html` e no topo do bloco `<script>` configure:

```javascript
const GROQ_API_KEY = 'gsk_...'; // a sua key Groq
```

O dashboard detecta automaticamente se está a correr com ou sem servidor e escolhe o modo adequado. **Não deixe a key preenchida em produção pública.**

---

## 🎨 Escala de Risco

| Nível | Score | Cor        | Significado                              |
|-------|-------|------------|------------------------------------------|
| 1     | 1–2   | 🟢 Verde   | Mínimo — situação estável                |
| 2     | 3–4   | 🟡 Amarelo | Moderado — vigilância recomendada        |
| 3     | 5–7   | 🟠 Laranja | Elevado — preparação activa              |
| 4     | 8–9   | 🔴 Vermelho| Crítico — acção imediata necessária      |
| 5     | 10    | 🟣 Magenta | Emergência — protocolo de crise activo   |

---

## 🔧 Vectores Analisados

| Ícone | Vector               | Indicadores-chave                          |
|-------|----------------------|--------------------------------------------|
| 🌍    | Geopolítica & Guerra | NATO, Rússia, Ucrânia, escalada            |
| ⚔️    | Ameaça Militar       | Forças Armadas, defesa territorial         |
| 📊    | Economia             | Inflação, dívida pública, emprego          |
| ⚡    | Energia              | Gás, petróleo, dependência externa         |
| 🏛️    | Ordem Social         | Governo, protestos, migrações              |
| 🔐    | Ciber-Segurança      | Infra-estruturas críticas, ataques         |
| 🏥    | Saúde Pública        | SNS, pandemias, surtos                     |
| 🌿    | Ambiente             | Sismos, incêndios, alterações climáticas   |
| 🌾    | Alimentar            | Abastecimento, cadeia logística, preços    |

---

## 💰 Custos

| Componente     | Custo         | Notas                                      |
|----------------|---------------|--------------------------------------------|
| Groq API       | **Gratuito**  | 14.400 req/dia · 6.000 tokens/min          |
| Llama 3.3 70B  | **Gratuito**  | Modelo incluído no plano free da Groq      |
| Hostinger      | ~3€/mês       | Plano partilhado básico é suficiente       |
| Domínio        | ~10€/ano      | Opcional                                   |

Com refresh de 30 minutos, o dashboard consome ~48 requests/dia — bem abaixo do limite gratuito.

---

## 🔒 Segurança

- A API key **nunca é exposta no frontend** — fica exclusivamente em `groq-proxy.php` no servidor
- O proxy valida o domínio de origem via `Access-Control-Allow-Origin`
- O `contador.txt` não contém dados pessoais — apenas contagens e timestamps de sessão anónimos
- Recomenda-se proteger `groq-proxy.php` e `contador.php` com `.htaccess` para bloquear acesso directo ao browser

```apache
# .htaccess — bloqueia acesso directo aos PHPs (opcional)
<FilesMatch "\.(php)$">
  <If "%{HTTP_REFERER} !~ /sentinel/">
    # Permite apenas chamadas internas
  </If>
</FilesMatch>
```

---

## 📡 Arquitectura

```
Browser
  │
  ├─── GET portugal-risk-dashboard.html
  │
  ├─── POST ./groq-proxy.php  ──────►  api.groq.com/v1/chat/completions
  │         (prompt JSON)              (Llama 3.3 70B)
  │         ◄── analysis JSON ◄────────────────────────────────────────
  │
  └─── POST ./contador.php    ──────►  contador.txt
            (session_id)               (leitura/escrita)
            ◄── { online, today, total }
```

---

## 🛠️ Stack Técnico

- **Frontend** — HTML5 + CSS3 + JavaScript vanilla (single file, sem dependências)
- **Backend** — PHP 8+ (dois ficheiros independentes)
- **IA** — Groq API · Llama 3.3 70B Versatile
- **Persistência** — ficheiro `contador.txt` (JSON) no servidor
- **Fonts** — Share Tech Mono · Barlow Condensed (Google Fonts)

---

## 📄 Licença

MIT — livre para uso pessoal e comercial com atribuição.

---

*Portugal Sentinel foi construído como ferramenta de consciência situacional. As análises são geradas por IA com base no contexto geopolítico disponível e não substituem fontes oficiais de informação governamental ou militar.*

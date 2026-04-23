# Sentinela — Monitor CPU

Dashboard web de monitoramento em tempo real para servidores Linux.  
Compatível com **ARM** (Orange Pi, Raspberry Pi) e **x86** (Intel, AMD) com ou sem GPU dedicada.

---

## Servidores Monitorados

| IP | Descrição |
|---|---|
| 10.0.0.139 | Servidor ARM — Frigate NVR |
| 10.0.0.141 | Servidor ARM |
| 10.0.0.5 | Servidor ARM |
| 10.0.0.37 | Servidor ARM |
| 10.0.2.148 | Servidor Intel — GPU NVIDIA RTX 3050, Docker |

---

## Estrutura do Projeto

```
/var/www/html/monitor-cpu/
├── index.html      → Dashboard web (CSS inline, sem dependências de path)
├── api.php         → API de coleta de métricas (JSON)
└── css/
    └── style.css   → Estilo (referenciado apenas como fallback)
```

O dashboard consome `/monitor-cpu/api.php` via AJAX a cada **3 segundos**.

---

## Métricas Monitoradas

### CPU
- Uso total (gauge com agulha)
- Uso por core (gráfico de barras, colorido por intensidade)
- Histórico em tempo real

### Temperatura
- Detectada automaticamente por plataforma:
  - **Intel/AMD**: `hwmon` → `coretemp` / `k10temp`
  - **NVIDIA**: `nvidia-smi`
  - **ARM**: `thermal_zone*` (zonas priorizadas por tipo)
- Gauge SVG com escala 0–80°C
- Card ocultado automaticamente se sensor indisponível

### Load Average
- Gráfico histórico (1m, 5m, 15m)

### Memória RAM
- Fonte: `/proc/meminfo` (`MemAvailable`)
- Barra com cor dinâmica: verde < 60%, laranja < 80%, vermelho ≥ 80%

### Disco
- Espaço usado / total na partição `/`
- I/O de leitura e escrita em KB/s via `iostat` (colunas detectadas dinamicamente)
- Fallback via `/proc/diskstats` se `iostat` não disponível

### Containers Docker
- Lista completa com estado: `running`, `exited`, `restarting`, `paused`
- Acesso via socket `/var/run/docker.sock` (sem sudo)
- Card com erro de diagnóstico se `www-data` não tiver permissão

### Frigate (opcional)
- Card visível apenas se container `frigate` estiver presente
- Não exibe erro se ausente

---

## Requisitos

### Sistema

| Componente | Obrigatório | Observação |
|---|---|---|
| PHP 7.4+ | ✅ | PHP 8.3 recomendado |
| Nginx ou Apache | ✅ | Detectado automaticamente |
| PHP-FPM | ✅ (Nginx) | Deve estar no grupo `docker` |
| Docker | Opcional | Para listar containers |
| iostat (sysstat) | Opcional | Para I/O de disco |
| lspci (pciutils) | Opcional | Para detecção de GPU |
| nvidia-smi | Opcional | Para GPU NVIDIA |

### Frontend (CDN)

```
https://cdn.jsdelivr.net/npm/chart.js
https://cdn.jsdelivr.net/npm/gaugeJS/dist/gauge.min.js
```

---

## Instalação

### 1. Copiar arquivos

```bash
cp -r monitor-cpu/ /var/www/html/
```

### 2. Permissões

```bash
sudo chown -R epaminondas:www-data /var/www/html/monitor-cpu
sudo chmod -R 775 /var/www/html/monitor-cpu
```

### 3. Configurar acesso ao Docker

```bash
sudo usermod -aG docker www-data
sudo systemctl restart nginx
sudo systemctl restart php8.3-fpm
```

### 4. Symlink (se necessário)

O browser pode acessar via `monitor_cpu` (underscore) ou `monitor-cpu` (hífen):

```bash
ln -s /var/www/html/monitor-cpu /var/www/html/monitor_cpu
```

### 5. Instalar dependências opcionais

```bash
# I/O de disco
sudo apt install sysstat -y

# Detecção de GPU
sudo apt install pciutils -y
```

### 6. Usar o script de setup automático

O projeto inclui `setup-sentinela.sh` que faz tudo acima automaticamente:

```bash
sudo bash /var/www/html/local-server-status/setup-sentinela.sh
```

---

## Acesso

```
http://IP_DO_SERVIDOR/monitor-cpu/
```

---

## Monitoramento Histórico (Sysstat / SAR)

### Instalar e ativar

```bash
sudo apt install sysstat -y
sudo systemctl enable --now sysstat
```

### Coleta a cada 1 minuto

Editar `/etc/cron.d/sysstat`:

```
* * * * * root command -v debian-sa1 > /dev/null && debian-sa1 1 1
```

### Retenção de 30 dias

Editar `/etc/sysstat/sysstat`:

```
HISTORY=30
```

```bash
sudo systemctl restart sysstat
```

### Consultas úteis

```bash
# CPU geral
sar -u

# Load average
sar -q

# CPU por core
sar -P ALL

# Dia específico
sar -u -f /var/log/sysstat/sa22
```

---

## Interpretação do Load Average

| Load (8 cores) | Situação |
|---|---|
| 0 – 2 | Excelente |
| 2 – 4 | Normal |
| 4 – 6 | Atenção |
| 6 – 8 | Pesado |
| > 8 | Saturado |

---

## Diagnóstico Pós-Travamento

```bash
# Substitua XX pelo dia do mês
sar -u -f /var/log/sysstat/saXX
sar -q -f /var/log/sysstat/saXX
sar -P ALL -f /var/log/sysstat/saXX
```

---

## Indicadores de Problema

- `%idle < 10%`
- `%iowait` elevado
- Load maior que o número de cores
- Containers com estado `restarting`
- Aumento repentino de I/O de disco
- SSH travando

---

## Autor

**Epaminondas Lage** — Engenheiro / Desenvolvedor de Sistemas

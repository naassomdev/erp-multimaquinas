// PM2 ecosystem — gerencia o microserviço em produção (VPS).
// Uso na VPS:
//   cd /www/wwwroot/multimaquinas.site/services/catalog-api
//   npm install --omit=dev
//   pm2 start ecosystem.config.cjs
//   pm2 save && pm2 startup            # autoinicialização no boot
module.exports = {
    apps: [
        {
            name: 'catalog-api',
            script: './server.js',
            cwd: __dirname,
            instances: 1,
            exec_mode: 'fork',
            autorestart: true,
            watch: false,
            max_memory_restart: '256M',
            env: {
                NODE_ENV: 'production',
                HOST: '127.0.0.1',
                PORT: '3001',
            },
            // Logs (rota relativa ao cwd)
            out_file:    './logs/out.log',
            error_file:  './logs/err.log',
            merge_logs:  true,
            time:        true,
        },
    ],
};

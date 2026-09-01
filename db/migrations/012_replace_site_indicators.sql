-- 012_replace_site_indicators.sql — os indicadores editáveis passam a ser os
-- seis números reais da operação (seção "Números reais da nossa operação" da
-- página ESG), no lugar dos cinco cards qualitativos da home, que voltaram a
-- ser conteúdo estático do site.

DELETE FROM site_indicators
WHERE indicator_key IN ('custo', 'aterro', 'docs', 'seguranca', 'esg');

INSERT IGNORE INTO site_indicators (indicator_key, value, label, symbol_type, symbol_value) VALUES
('pessoas', '300 Mil', 'Pessoas impactadas', 'icon', 'ImpactPeopleIcon'),
('co2', '190 tCO₂e', 'CO₂ evitado', 'icon', 'ImpactCarbonIcon'),
('energia', '300 Mil kWh', 'Energia economizada', 'icon', 'ImpactEnergyIcon'),
('arvores', '1.300', 'Árvores preservadas', 'icon', 'ImpactTreesIcon'),
('carros', '120', 'Carros fora de circulação', 'icon', 'ImpactCarsIcon'),
('agua', '7 Mi', 'Litros de água poupada', 'icon', 'ImpactWaterIcon');

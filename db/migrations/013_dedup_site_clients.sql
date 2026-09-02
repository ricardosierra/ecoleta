-- 013_dedup_site_clients.sql — remove empresas duplicadas e impede que voltem.
--
-- O seed 010 usava INSERT IGNORE, mas a tabela não tinha chave única em `name`:
-- rodar o seed mais de uma vez (o que aconteceu em produção) duplicava as 23
-- empresas inteiras. Aqui ficam só as linhas de menor id por nome, e a UNIQUE
-- faz qualquer repetição futura falhar em vez de duplicar em silêncio.

DELETE sc FROM site_clients sc
INNER JOIN site_clients keeper
        ON keeper.name = sc.name
       AND keeper.id < sc.id;

ALTER TABLE site_clients
    ADD UNIQUE KEY uq_site_clients_name (name);

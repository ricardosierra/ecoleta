export type BlogSource = {
  label: string;
  url: string;
};

export type BlogSection = {
  heading: string;
  body: string[];
};

export type BlogPost = {
  slug: string;
  title: string;
  description: string;
  category: string;
  publishedAt: string;
  updatedAt: string;
  readingTime: string;
  keywords: string[];
  intro: string;
  takeaways: string[];
  sections: BlogSection[];
  sources: BlogSource[];
};

export const blogPosts: BlogPost[] = [
  {
    slug: "mtr-cdf-rastreabilidade-residuos",
    title: "MTR e CDF: como comprovar a destinação correta dos resíduos",
    description:
      "Entenda o papel do Manifesto de Transporte de Resíduos e do Certificado de Destinação Final na rastreabilidade ambiental de empresas e eventos.",
    category: "Documentação ambiental",
    publishedAt: "2026-05-02",
    updatedAt: "2026-05-02",
    readingTime: "4 min",
    keywords: ["MTR", "CDF", "rastreabilidade de resíduos", "SINIR", "destinação final"],
    intro:
      "Quando a operação gera resíduos, a comprovação não termina na coleta. O que dá segurança para auditorias, patrocinadores e órgãos ambientais é a cadeia documental que mostra origem, transporte, tratamento e destinação final.",
    takeaways: [
      "MTR registra a movimentação do resíduo no SINIR.",
      "CDF comprova a destinação ambientalmente adequada após o recebimento pelo destinador.",
      "Fotos, pesagens, checklists e relatórios fecham a rastreabilidade operacional.",
    ],
    sections: [
      {
        heading: "O que o MTR registra",
        body: [
          "O MTR, Manifesto de Transporte de Resíduos, é o documento usado para registrar a movimentação de resíduos sólidos no SINIR. Ele conecta gerador, transportador, armazenador temporário e destinador, criando um histórico rastreável da carga.",
          "Na prática, o MTR ajuda a controlar massa, geração, armazenamento temporário, transporte e destinação final. Para empresas e eventos, isso reduz a dependência de controles informais e transforma a operação em evidência organizada.",
        ],
      },
      {
        heading: "Por que o CDF fecha a comprovação",
        body: [
          "O CDF, Certificado de Destinação Final, é emitido pelo destinador e comprova que os resíduos recebidos tiveram tratamento ou destinação ambientalmente adequada.",
          "Sem CDF, a empresa pode até ter coletado corretamente, mas fica com uma lacuna na prova final. Por isso, a gestão precisa acompanhar o ciclo inteiro, da segregação até o certificado.",
        ],
      },
      {
        heading: "Como a Ecoleva organiza essa trilha",
        body: [
          "A operação fica mais segura quando cada fração tem identificação, pesagem, registro fotográfico, responsável definido e documentação associada. Esse conjunto é o que permite transformar coleta em relatório ambiental.",
          "Para eventos, indústrias e espaços de entretenimento, a rastreabilidade também melhora a comunicação com patrocinadores e parceiros, porque os dados deixam de ser promessa e passam a ser comprovação.",
        ],
      },
    ],
    sources: [
      { label: "SINIR - MTR", url: "https://sinir.gov.br/sistemas/mtr" },
      {
        label: "Gov.br - Certificado de Destinação Final de resíduos",
        url: "https://www.gov.br/pt-br/servicos/obter-certificado-de-destinacao-final-de-residuos-1?id=13359&origem=servico",
      },
    ],
  },
  {
    slug: "pgrs-na-pratica-controle-operacional",
    title: "PGRS na prática: quando o plano vira controle operacional",
    description:
      "Veja como um Plano de Gerenciamento de Resíduos Sólidos pode sair do papel e orientar segregação, coleta, indicadores e conformidade.",
    category: "PGRS",
    publishedAt: "2026-05-02",
    updatedAt: "2026-05-02",
    readingTime: "5 min",
    keywords: ["PGRS", "PNRS", "gestão de resíduos sólidos", "conformidade ambiental"],
    intro:
      "Um PGRS não deveria ser apenas um documento guardado para fiscalização. Quando bem aplicado, ele vira o mapa de decisão da operação: o que separar, onde armazenar, quando coletar, como comprovar e quais indicadores acompanhar.",
    takeaways: [
      "A PNRS organiza diretrizes para a gestão de resíduos no Brasil.",
      "O plano precisa conversar com a rotina real da operação.",
      "Treinamento e supervisão reduzem mistura de resíduos e perda de recicláveis.",
    ],
    sections: [
      {
        heading: "A lógica da PNRS aplicada à rotina",
        body: [
          "A Política Nacional de Resíduos Sólidos foi instituída pela Lei 12.305/2010 e regulamentada por normas posteriores. Na rotina, a hierarquia que importa é simples: reduzir, reutilizar, reciclar, tratar e destinar rejeitos corretamente.",
          "Essa lógica evita que tudo vire lixo comum. Cada material passa a ter caminho definido, responsável e evidência de destinação.",
        ],
      },
      {
        heading: "O que faz um PGRS funcionar",
        body: [
          "O plano funciona quando descreve a operação como ela realmente acontece: pontos de geração, tipos de resíduos, recipientes, frequência de coleta, transportadores, destinadores, responsáveis e documentos exigidos.",
          "Se o PGRS não chega até a equipe de campo, ele perde força. Placas, cores, treinamento e supervisão técnica são o que transformam o planejamento em comportamento repetível.",
        ],
      },
      {
        heading: "Indicadores que mostram evolução",
        body: [
          "A Ecoleva recomenda acompanhar massa por fração, percentual de desvio de aterro, custo por etapa, ocorrências de mistura, status de MTR/CDF e registros fotográficos por período.",
          "Esses dados mostram onde existe desperdício e onde a operação pode reduzir custo sem perder conformidade.",
        ],
      },
    ],
    sources: [
      {
        label: "Gov.br - Política Nacional de Resíduos Sólidos",
        url: "https://www.gov.br/mdic/pt-br/assuntos/enec/legislacao/politica-de-residuos/politica-nacional-de-residuos-solidos-regulamentacoes",
      },
      { label: "SINIR - MTR", url: "https://sinir.gov.br/sistemas/mtr" },
    ],
  },
  {
    slug: "esg-residuos-dados-auditaveis",
    title: "ESG em resíduos: como transformar coleta em dados auditáveis",
    description:
      "Gestão de resíduos só vira ESG quando há dados, rastreabilidade e documentação para sustentar indicadores ambientais.",
    category: "ESG",
    publishedAt: "2026-05-02",
    updatedAt: "2026-05-02",
    readingTime: "4 min",
    keywords: ["ESG", "relatório ESG", "GHG Protocol", "resíduos", "indicadores ambientais"],
    intro:
      "ESG não se sustenta com frases genéricas. Em resíduos, a credibilidade vem de indicadores verificáveis: massa gerada, frações separadas, destinação, documentos, fornecedores e evolução ao longo do tempo.",
    takeaways: [
      "Resíduos gerados na operação podem entrar no escopo 3 do GHG Protocol.",
      "O relatório ESG precisa de evidências operacionais, não apenas estimativas soltas.",
      "Rastreabilidade melhora governança e reduz risco reputacional.",
    ],
    sections: [
      {
        heading: "Por que resíduos entram na conversa de emissões",
        body: [
          "No GHG Protocol, resíduos gerados nas operações aparecem na categoria 5 do escopo 3, quando o tratamento ou disposição acontece em instalações de terceiros.",
          "Isso torna a qualidade dos dados de resíduos relevante para inventários, metas e comunicação ambiental. Sem massa por fração e sem destino identificado, o indicador fica frágil.",
        ],
      },
      {
        heading: "O que um relatório precisa mostrar",
        body: [
          "Um bom relatório separa recicláveis, orgânicos, rejeitos, infectantes e outras frações conforme a realidade da operação. Também informa destino, documentação, período, responsáveis e evolução dos indicadores.",
          "A combinação de MTR, CDF, pesagens, fotos e checklists cria uma base mais auditável para clientes, patrocinadores e áreas de compliance.",
        ],
      },
      {
        heading: "ESG aplicado, não decorativo",
        body: [
          "Quando a gestão é feita no campo, a empresa ganha controle. Quando os dados são consolidados, a marca ganha argumento. Esse é o ponto em que a operação deixa de ser custo invisível e passa a apoiar reputação, economia e governança.",
        ],
      },
    ],
    sources: [
      {
        label: "GHG Protocol - Scope 3 Frequently Asked Questions",
        url: "https://ghgprotocol.org/scope-3-frequently-asked-questions-0",
      },
      {
        label: "GHG Protocol - Scope 3 Calculation Guidance",
        url: "https://ghgprotocol.org/scope-3-calculation-guidance-2",
      },
      { label: "SINIR - MTR", url: "https://sinir.gov.br/sistemas/mtr" },
    ],
  },
  {
    slug: "gestao-residuos-eventos-patrocinadores",
    title: "Gestão de resíduos em eventos: o que patrocinadores esperam ver",
    description:
      "Eventos precisam de operação limpa, segregação eficiente, documentação ambiental e indicadores claros para prestação de contas.",
    category: "Eventos",
    publishedAt: "2026-05-02",
    updatedAt: "2026-05-02",
    readingTime: "5 min",
    keywords: ["gestão de resíduos em eventos", "resíduos eventos", "patrocinadores ESG", "coleta seletiva"],
    intro:
      "Em eventos, o desafio não é apenas recolher resíduos rapidamente. É controlar picos de geração, orientar público e equipes, evitar acúmulo, separar frações e entregar comprovação ambiental depois que a montagem já foi desmontada.",
    takeaways: [
      "Eventos precisam de planejamento antes da abertura de portões.",
      "Sinalização e código de cores reduzem erro na separação.",
      "Patrocinadores tendem a exigir relatório claro, documentos e indicadores.",
    ],
    sections: [
      {
        heading: "O risco está no improviso",
        body: [
          "Eventos concentram geração de resíduos em poucas horas. Sem pontos de descarte bem posicionados, equipe treinada e coleta por janela operacional, o resultado costuma ser mistura, acúmulo e perda de recicláveis.",
          "A operação precisa ser desenhada antes: mapa de pontos, fluxo de retirada, responsáveis, área temporária, destino por fração e rotina de evidências.",
        ],
      },
      {
        heading: "Separação visível ajuda a operação",
        body: [
          "A Resolução CONAMA 275/2001 estabeleceu o código de cores para diferentes tipos de resíduos na identificação de coletores, transportadores e campanhas de coleta seletiva.",
          "Na prática, cor, placa e posicionamento precisam trabalhar juntos. Um coletor correto em local errado ainda gera descarte errado.",
        ],
      },
      {
        heading: "O que entregar ao fim do evento",
        body: [
          "O pós-evento deve consolidar massa por fração, destino, documentação, registros fotográficos e aprendizados para a próxima edição.",
          "Essa entrega é especialmente importante quando existe patrocinador, auditoria ou compromisso público de sustentabilidade.",
        ],
      },
    ],
    sources: [
      {
        label: "CONAMA - Resolução 275/2001",
        url: "https://conama.mma.gov.br/index.php?id=1356&option=com_sisconama&view=processo",
      },
      { label: "SINIR - MTR", url: "https://sinir.gov.br/sistemas/mtr" },
    ],
  },
  {
    slug: "residuos-texteis-rastreabilidade-economia-circular",
    title: "Resíduos têxteis: por que rastrear retalhos, peças e descartes",
    description:
      "Resíduos têxteis podem ganhar rotas de reuso, reciclagem e upcycling quando a operação separa, registra e comprova a destinação.",
    category: "Economia circular",
    publishedAt: "2026-05-02",
    updatedAt: "2026-05-02",
    readingTime: "4 min",
    keywords: ["resíduos têxteis", "economia circular", "upcycling", "rastreabilidade ambiental"],
    intro:
      "O descarte têxtil costuma parecer simples, mas mistura de materiais, contaminação e falta de triagem reduzem drasticamente as possibilidades de reuso, reciclagem e upcycling.",
    takeaways: [
      "Triagem correta aumenta chance de reaproveitamento.",
      "Rastreabilidade ajuda marcas a demonstrar destino e impacto.",
      "Parcerias de economia circular conectam resíduo a novos produtos.",
    ],
    sections: [
      {
        heading: "Por que têxtil exige separação própria",
        body: [
          "Roupas, tecidos, retalhos e peças de ativação podem ter destinos diferentes. Quando tudo é descartado junto, materiais aproveitáveis perdem valor e podem virar rejeito.",
          "A gestão começa na origem: pontos de coleta definidos, separação por condição do material, armazenamento adequado e registro do volume gerado.",
        ],
      },
      {
        heading: "Da triagem ao reaproveitamento",
        body: [
          "Com parceiros adequados, parte dos materiais pode seguir para doação, reciclagem, transformação em novos produtos ou ações de upcycling.",
          "Esse fluxo precisa ser comprovável. A marca ganha mais segurança quando consegue mostrar o que foi gerado, para onde foi e qual foi o resultado ambiental.",
        ],
      },
      {
        heading: "Como isso fortalece reputação",
        body: [
          "A Ecoleva trata resíduos têxteis como parte da estratégia multiresíduos. O objetivo é reduzir envio a aterro, aumentar controle e transformar descarte em uma narrativa concreta de economia circular.",
        ],
      },
    ],
    sources: [
      {
        label: "Gov.br - Política Nacional de Resíduos Sólidos",
        url: "https://www.gov.br/mdic/pt-br/assuntos/enec/legislacao/politica-de-residuos/politica-nacional-de-residuos-solidos-regulamentacoes",
      },
      {
        label: "GHG Protocol - Scope 3 Calculation Guidance",
        url: "https://ghgprotocol.org/scope-3-calculation-guidance-2",
      },
    ],
  },
];

export function getBlogPost(slug: string) {
  return blogPosts.find((post) => post.slug === slug);
}

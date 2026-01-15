import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Solo Base Repository',
  description: 'Lightweight PHP repository pattern with soft delete and eager loading.',
  base: '/Base-Repository/',
  
  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/Base-Repository/logo.svg' }],
    ['meta', { name: 'theme-color', content: '#06b6d4' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:title', content: 'Solo Base Repository' }],
    ['meta', { property: 'og:description', content: 'Lightweight PHP repository pattern with soft delete and eager loading' }],
  ],

  themeConfig: {
    logo: '/logo.svg',
    
    nav: [
      { text: 'Guide', link: '/guide/installation' },
      { text: 'API', link: '/methods/retrieval' },
      {
        text: 'Links',
        items: [
          { text: 'GitHub', link: 'https://github.com/solophp/base-repository' },
          { text: 'Packagist', link: 'https://packagist.org/packages/solophp/base-repository' },
          { text: 'SoloPHP', link: 'https://github.com/solophp' }
        ]
      }
    ],

    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Installation', link: '/guide/installation' },
          { text: 'Quick Start', link: '/guide/quick-start' },
          { text: 'Configuration', link: '/guide/configuration' }
        ]
      },
      {
        text: 'Methods',
        items: [
          { text: 'Retrieval', link: '/methods/retrieval' },
          { text: 'Mutation', link: '/methods/mutation' },
          { text: 'Aggregates', link: '/methods/aggregates' },
          { text: 'Transactions', link: '/methods/transactions' }
        ]
      },
      {
        text: 'Features',
        items: [
          { text: 'Criteria Syntax', link: '/features/criteria' },
          { text: 'Soft Delete', link: '/features/soft-delete' },
          { text: 'Eager Loading', link: '/features/eager-loading' }
        ]
      },
      {
        text: 'Advanced',
        items: [
          { text: 'Custom IDs', link: '/advanced/custom-ids' },
          { text: 'Extending Repositories', link: '/advanced/extending' }
        ]
      }
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/solophp/base-repository' }
    ],

    footer: {
      message: 'Released under the MIT License.',
      copyright: `Copyright © 2025-${new Date().getFullYear()} SoloPHP`
    },

    search: {
      provider: 'local'
    },

    editLink: {
      pattern: 'https://github.com/solophp/base-repository/edit/main/docs/:path',
      text: 'Edit this page on GitHub'
    }
  }
})
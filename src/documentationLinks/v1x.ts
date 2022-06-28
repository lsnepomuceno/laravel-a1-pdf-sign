import { Doc } from '@/composables/useDoc'

export default <Doc>{
    version: '1.x',
    sections: [
        {
            title: 'Home',
            url: 'home',
            icon: 'fa-house',
            footerActions: {
                nextLink: 'installation'
            }
        },
        {
            title: 'Installation',
            url: 'installation',
            icon: 'fa-screwdriver-wrench',
            footerActions: {
                previousLink: 'home',
                nextLink: 'usage'
            }
        },
        {
            title: 'Usage',
            url: 'usage',
            icon: 'fa-signature',
            subSections: [
                {
                    title: 'Working with certificate',
                    url: 'working-with-certificate',
                    footerActions: {
                        previousLink: 'usage',
                        nextLink: 'sign-pdf-file'
                    }
                },
                {
                    title: 'Sign PDF File',
                    url: 'sign-pdf-file',
                    footerActions: {
                        previousLink: 'working-with-certificate',
                        nextLink: 'validating-signature'
                    }
                },
                {
                    title: 'Validating signature',
                    url: 'validating-signature',
                    footerActions: {
                        previousLink: 'sign-pdf-file',
                        nextLink: 'helpers'
                    }
                },
                {
                    title: 'Helpers',
                    url: 'helpers',
                    footerActions: {
                        previousLink: 'validating-signature',
                        nextLink: 'not-laravel-or-lumen'
                    }
                }
            ],
            footerActions: {
                previousLink: 'installation',
                nextLink: 'not-laravel-or-lumen'
            }
        },
        {
            title: 'Not Laravel or Lumen app',
            url: 'not-laravel-or-lumen',
            icon: 'fa-exclamation',
            footerActions: {
                previousLink: 'helpers',
                nextLink: 'tests'
            }
        },
        {
            title: 'Tests',
            url: 'tests',
            icon: 'fa-bug',
            footerActions: {
                previousLink: 'not-laravel-or-lumen'
            }
        }
    ]
}

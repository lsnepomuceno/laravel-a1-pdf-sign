import type { Doc } from "@/interfaces/Doc";

export default <Doc>{
    version: "2.x",
    sections: [
        {
            title: "Release notes",
            url: "release-notes",
            icon: "fa-code-compare",
            footerActions: {
                nextLink: "home",
            },
        },
        {
            title: "Home",
            url: "home",
            icon: "fa-house",
            footerActions: {
                previousLink: "release-notes",
                nextLink: "upgrade-from-1x",
            },
        },
        {
            title: "Upgrading from 1.x",
            url: "upgrade-from-1x",
            icon: "fa-arrow-up-right-dots",
            footerActions: {
                previousLink: "home",
                nextLink: "installation",
            },
        },
        {
            title: "Installation",
            url: "installation",
            icon: "fa-screwdriver-wrench",
            footerActions: {
                previousLink: "upgrade-from-1x",
                nextLink: "usage",
            },
        },
        {
            title: "Usage",
            url: "usage",
            icon: "fa-signature",
            subSections: [
                {
                    title: "Working with certificates",
                    url: "working-with-certificate",
                    footerActions: {
                        previousLink: "usage",
                        nextLink: "sign-pdf-file",
                    },
                },
                {
                    title: "Sign PDF file",
                    url: "sign-pdf-file",
                    footerActions: {
                        previousLink: "working-with-certificate",
                        nextLink: "signature-profiles",
                    },
                },
                {
                    title: "Signature profiles",
                    url: "signature-profiles",
                    footerActions: {
                        previousLink: "sign-pdf-file",
                        nextLink: "validating-signature",
                    },
                },
                {
                    title: "Validating signature",
                    url: "validating-signature",
                    footerActions: {
                        previousLink: "signature-profiles",
                        nextLink: "icp-brasil",
                    },
                },
                {
                    title: "ICP-Brasil",
                    url: "icp-brasil",
                    footerActions: {
                        previousLink: "validating-signature",
                        nextLink: "testing",
                    },
                },
                {
                    title: "Testing your application",
                    url: "testing",
                    footerActions: {
                        previousLink: "icp-brasil",
                        nextLink: "auditing",
                    },
                },
                {
                    title: "Auditing",
                    url: "auditing",
                    footerActions: {
                        previousLink: "testing",
                        nextLink: "commands",
                    },
                },
            ],
            footerActions: {
                previousLink: "installation",
                nextLink: "commands",
            },
        },
        {
            title: "Commands",
            url: "commands",
            icon: "fa-terminal",
            footerActions: {
                previousLink: "auditing",
                nextLink: "not-laravel-or-lumen",
            },
        },
        {
            title: "Not Laravel or Lumen app",
            url: "not-laravel-or-lumen",
            icon: "fa-exclamation",
            footerActions: {
                previousLink: "commands",
                nextLink: "tests",
            },
        },
        {
            title: "Tests",
            url: "tests",
            icon: "fa-bug",
            footerActions: {
                previousLink: "not-laravel-or-lumen",
            },
        },
    ],
};

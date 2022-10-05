import type { Doc } from "@/interfaces/Doc";

export default <Doc>{
    version: "1.x",
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
                nextLink: "installation",
            },
        },
        {
            title: "Installation",
            url: "installation",
            icon: "fa-screwdriver-wrench",
            footerActions: {
                previousLink: "home",
                nextLink: "usage",
            },
        },
        {
            title: "Usage",
            url: "usage",
            icon: "fa-signature",
            subSections: [
                {
                    title: "Working with certificate",
                    url: "working-with-certificate",
                    footerActions: {
                        previousLink: "usage",
                        nextLink: "sign-pdf-file",
                    },
                },
                {
                    title: "Sign PDF File",
                    url: "sign-pdf-file",
                    footerActions: {
                        previousLink: "working-with-certificate",
                        nextLink: "validating-signature",
                    },
                },
                {
                    title: "Validating signature",
                    url: "validating-signature",
                    footerActions: {
                        previousLink: "sign-pdf-file",
                        nextLink: "helpers",
                    },
                },
                {
                    title: "Helpers",
                    url: "helpers",
                    footerActions: {
                        previousLink: "validating-signature",
                        nextLink: "not-laravel-or-lumen",
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
                previousLink: "usage",
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

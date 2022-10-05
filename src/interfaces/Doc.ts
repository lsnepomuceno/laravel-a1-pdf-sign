export interface FooterActions {
    previousLink?: string
    nextLink?: string
}

export interface SubSection {
    title: string
    url: string
    footerActions?: FooterActions
}

export interface Section {
    title: string
    url: string
    icon: string
    subSections?: SubSection[]
    footerActions?: FooterActions
}

export interface Doc {
    version: string;
    sections: Section[];
}

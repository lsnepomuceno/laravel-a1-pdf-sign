export interface FooterActions {
  previousLink?: string;
  nextLink?: string;
}

export interface Doc {
  version: string;
  sections: {
    title: string;
    url: string;
    icon: string;
    subSections?: {
      title: string;
      url: string;
      footerActions?: FooterActions;
    }[];
    footerActions?: FooterActions;
  }[];
}

import { usePage } from "@inertiajs/react";
import type { Shared } from "@/lib/types";
import { PublicWebsite } from "@/components/PublicWebsite";
import { ApplicationFields } from "@/components/ApplicationFields";
import { Head, Link } from "@inertiajs/react";
import { localized, siteStyle } from "@/lib/website";
export default function WebsitePage({
    slug = "",
    preview = false,
}: {
    slug?: string;
    preview?: boolean;
}) {
    const website = usePage<Shared>().props.website;
    const d = localized(website, website.languages.default);
    if (preview && ["@application", "@login"].includes(slug)) {
        return (
            <div className="site-root application-preview" style={siteStyle(d)}>
                <Head title="Private draft preview">
                    <meta name="robots" content="noindex,nofollow" />
                </Head>
                <p>
                    Private draft preview ·{" "}
                    <Link href="/website/customize">Return to editor</Link>
                </p>
                {slug === "@application" ? (
                    <>
                        <h1>{d.application.title}</h1>
                        <p>{d.application.intro}</p>
                        {d.application.open ? (
                            <>
                                <ApplicationFields document={d} preview />
                                <button className="site-button" disabled>
                                    {d.application.button_label}
                                </button>
                            </>
                        ) : (
                            <p>{d.application.closed_message}</p>
                        )}
                    </>
                ) : (
                    <>
                        <h1>{d.login.title}</h1>
                        <p>{d.login.body}</p>
                        {d.login.image && <img src={d.login.image} alt="" />}
                        <label>
                            Email address
                            <input disabled />
                        </label>
                        <label>
                            Password
                            <input disabled />
                        </label>
                        <p>{d.login.help_text}</p>
                    </>
                )}
            </div>
        );
    }
    return <PublicWebsite document={website} slug={slug} preview={preview} />;
}

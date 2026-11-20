type Appointment = { title?: string | null; place?: string | null; line?: string | null } | null | undefined;

/** The partner's appointment in one green badge: line 1 the kind of dealer, line 2 the place and the places above it. */
export default function AppointmentPill({ appointment, fallback, testId }: { appointment: Appointment; fallback?: string; testId?: string }) {
    const title = appointment?.title || appointment?.line || fallback || '';
    if (!title) {
        return null;
    }

    return (
        <span className="appt-pill" data-testid={testId}>
            <b className="appt-title">{title}</b>
            {appointment?.place && <span className="appt-place">{appointment.place}</span>}
        </span>
    );
}

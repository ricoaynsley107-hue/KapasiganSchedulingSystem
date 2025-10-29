# ml/scripts/1_export_data.py
import mysql.connector
import pandas as pd
from config import DB_CONFIG, DATA_PATH
from datetime import datetime

def export_training_data():
    """Export historical booking data for ML training"""
    
    print("🔄 Connecting to database...")
    conn = mysql.connector.connect(**DB_CONFIG)
    
    # Query to get comprehensive booking history
    query = """
    SELECT 
        fb.id as booking_id,
        fb.user_id,
        fb.facility_id,
        fb.booking_date,
        HOUR(fb.start_time) as hour_of_day,
        DAYOFWEEK(fb.booking_date) as day_of_week,
        DATEDIFF(fb.booking_date, fb.created_at) as advance_booking_days,
        TIME_TO_SEC(TIMEDIFF(fb.end_time, fb.start_time))/3600 as duration_hours,
        fb.status,
        fb.request_type,
        fb.expected_attendees,
        fb.actual_attendees,
        
        -- User history
        COUNT(DISTINCT fb2.id) as user_total_bookings,
        SUM(CASE WHEN fb2.status = 'approved' THEN 1 ELSE 0 END) as user_approved_count,
        SUM(CASE WHEN fb2.status = 'completed' THEN 1 ELSE 0 END) as user_completed_count,
        SUM(CASE WHEN fb2.status = 'cancelled' THEN 1 ELSE 0 END) as user_cancelled_count,
        
        -- Attendance indicator (for no-show prediction)
        CASE 
            WHEN fb.actual_attendees >= fb.expected_attendees * 0.8 THEN 1 
            ELSE 0 
        END as attendance_success,
        
        -- Facility demand
        (SELECT COUNT(*) FROM facility_bookings fb3 
         WHERE fb3.facility_id = fb.facility_id 
         AND fb3.booking_date = fb.booking_date) as same_day_facility_demand
        
    FROM facility_bookings fb
    LEFT JOIN facility_bookings fb2 
        ON fb.user_id = fb2.user_id 
        AND fb2.created_at < fb.created_at
    WHERE fb.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY fb.id
    
    UNION ALL
    
    -- Include item borrowings
    SELECT 
        ib.id as booking_id,
        ib.user_id,
        ib.item_id as facility_id,
        ib.borrow_date as booking_date,
        12 as hour_of_day,  -- Default noon
        DAYOFWEEK(ib.borrow_date) as day_of_week,
        DATEDIFF(ib.borrow_date, ib.created_at) as advance_booking_days,
        DATEDIFF(ib.return_date, ib.borrow_date) as duration_hours,
        ib.status,
        ib.request_type,
        0 as expected_attendees,
        0 as actual_attendees,
        
        COUNT(DISTINCT ib2.id) as user_total_bookings,
        SUM(CASE WHEN ib2.status = 'approved' THEN 1 ELSE 0 END) as user_approved_count,
        SUM(CASE WHEN ib2.status = 'returned' THEN 1 ELSE 0 END) as user_completed_count,
        SUM(CASE WHEN ib2.status = 'cancelled' THEN 1 ELSE 0 END) as user_cancelled_count,
        
        CASE WHEN ib.actual_return_date IS NOT NULL THEN 1 ELSE 0 END as attendance_success,
        
        (SELECT COUNT(*) FROM item_borrowings ib3 
         WHERE ib3.item_id = ib.item_id 
         AND ib3.borrow_date = ib.borrow_date) as same_day_facility_demand
        
    FROM item_borrowings ib
    LEFT JOIN item_borrowings ib2 
        ON ib.user_id = ib2.user_id 
        AND ib2.created_at < ib.created_at
    WHERE ib.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY ib.id
    
    UNION ALL
    
    -- Include vehicle requests
    SELECT 
        vr.id as booking_id,
        vr.user_id,
        vr.vehicle_id as facility_id,
        vr.request_date as booking_date,
        HOUR(vr.start_time) as hour_of_day,
        DAYOFWEEK(vr.request_date) as day_of_week,
        DATEDIFF(vr.request_date, vr.created_at) as advance_booking_days,
        TIME_TO_SEC(TIMEDIFF(vr.end_time, vr.start_time))/3600 as duration_hours,
        vr.status,
        vr.request_type,
        vr.passenger_count as expected_attendees,
        vr.passenger_count as actual_attendees,
        
        COUNT(DISTINCT vr2.id) as user_total_bookings,
        SUM(CASE WHEN vr2.status = 'approved' THEN 1 ELSE 0 END) as user_approved_count,
        SUM(CASE WHEN vr2.status = 'completed' THEN 1 ELSE 0 END) as user_completed_count,
        SUM(CASE WHEN vr2.status = 'cancelled' THEN 1 ELSE 0 END) as user_cancelled_count,
        
        CASE WHEN vr.status = 'completed' THEN 1 ELSE 0 END as attendance_success,
        
        (SELECT COUNT(*) FROM vehicle_requests vr3 
         WHERE vr3.vehicle_id = vr.vehicle_id 
         AND vr3.request_date = vr.request_date) as same_day_facility_demand
        
    FROM vehicle_requests vr
    LEFT JOIN vehicle_requests vr2 
        ON vr.user_id = vr2.user_id 
        AND vr2.created_at < vr.created_at
    WHERE vr.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY vr.id
    """
    
    print("📊 Executing query...")
    df = pd.read_sql(query, conn)
    conn.close()
    
    # Calculate additional features
    df['user_approval_rate'] = df['user_approved_count'] / (df['user_total_bookings'] + 1)
    df['user_completion_rate'] = df['user_completed_count'] / (df['user_approved_count'] + 1)
    df['is_weekend'] = df['day_of_week'].isin([1, 7]).astype(int)  # 1=Sunday, 7=Saturday
    
    # Save to CSV
    output_file = f"{DATA_PATH}training_data.csv"
    df.to_csv(output_file, index=False)
    
    print(f"✅ Data exported successfully!")
    print(f"📁 File: {output_file}")
    print(f"📊 Total records: {len(df)}")
    print(f"📈 Approved: {df[df['status']=='approved'].shape[0]}")
    print(f"📉 Denied: {df[df['status']=='denied'].shape[0]}")
    print(f"⏳ Pending: {df[df['status']=='pending'].shape[0]}")
    
    return df

if __name__ == "__main__":
    export_training_data()
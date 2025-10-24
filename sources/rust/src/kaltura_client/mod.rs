pub mod enums;

/// Classes provided as part of the [Kaltura API Client Library](https://developer.kaltura.com/api-docs/General_Objects/Objects)
///
/// ## Architecture
///
/// Because the Kaltura API Client is object oriented, certain abstractions have been chosen to
/// approximate inheritance.
///
/// Assuming there is a class `KalturaC`, which inherits from `KalturaB`, which inherits from
/// `KalturaA`, this relationship would be expressed as such:
///
/// ```rust
/// struct KalturaA;
///
/// trait IKalturaA {};
///
/// struct KalturaB;
/// impl IKalturaA for KalturaB {}
/// trait IKalturaB: IKalturaA {}
///
/// struct KalturaC;
///
/// impl IKalturaA for KalturaC {}
/// impl IKalturaB for KalturaC {}
///
/// fn use_a(x: impl KalturaA) {}
/// fn use_b(x: impl KalturaB) {}
/// # fn main() {
///
/// let c = KalturaC;
/// use_a(c);
/// use_b(c);
/// # }
/// ```
pub mod classes;
pub mod traits;
